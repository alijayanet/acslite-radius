const fs = require('fs');
const path = require('path');
const logger = require('./logger');
const { getSetting } = require('./settingsManager');
const { sendMessage, setSock } = require('./sendMessage');

// Helper function untuk format tanggal Indonesia yang benar
function formatIndonesianDateTime(date = new Date()) {
  try {
    // Handle potential system time issues
    let targetDate = new Date(date);
    
    // If system time is way off (like 2025), try to fix it
    const currentYear = targetDate.getFullYear();
    if (currentYear > 2024) {
      // Assume it should be 2024 and adjust
      const yearDiff = currentYear - 2024;
      targetDate = new Date(targetDate.getTime() - (yearDiff * 365 * 24 * 60 * 60 * 1000));
    }
    
    // Convert to Indonesian timezone (UTC+7)
    const options = {
      timeZone: 'Asia/Jakarta',
      day: '2-digit',
      month: '2-digit', 
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false
    };
    
    const formatter = new Intl.DateTimeFormat('id-ID', options);
    const parts = formatter.formatToParts(targetDate);
    
    const day = parts.find(part => part.type === 'day').value;
    const month = parts.find(part => part.type === 'month').value;
    const year = parts.find(part => part.type === 'year').value;
    const hour = parts.find(part => part.type === 'hour').value;
    const minute = parts.find(part => part.type === 'minute').value;
    const second = parts.find(part => part.type === 'second').value;
    
    return `${day}/${month}/${year} ${hour}:${minute}:${second}`;
  } catch (error) {
    // Fallback to simple format if anything fails
    const d = new Date(date);
    const day = d.getDate().toString().padStart(2, '0');
    const month = (d.getMonth() + 1).toString().padStart(2, '0');
    const year = 2024; // Force 2024 as fallback
    const hour = d.getHours().toString().padStart(2, '0');
    const minute = d.getMinutes().toString().padStart(2, '0');
    const second = d.getSeconds().toString().padStart(2, '0');
    
    return `${day}/${month}/${year} ${hour}:${minute}:${second}`;
  }
}

// Path untuk menyimpan data laporan gangguan
const troubleReportPath = path.join(__dirname, '../logs/trouble_reports.json');

// Memastikan file laporan gangguan ada
function ensureTroubleReportFile() {
  try {
    if (!fs.existsSync(path.dirname(troubleReportPath))) {
      fs.mkdirSync(path.dirname(troubleReportPath), { recursive: true });
    }
    
    if (!fs.existsSync(troubleReportPath)) {
      fs.writeFileSync(troubleReportPath, JSON.stringify([], null, 2), 'utf8');
      logger.info(`File laporan gangguan dibuat: ${troubleReportPath}`);
    }
  } catch (error) {
    logger.error(`Gagal membuat file laporan gangguan: ${error.message}`);
  }
}

// Mendapatkan semua laporan gangguan
function getAllTroubleReports() {
  ensureTroubleReportFile();
  try {
    const data = fs.readFileSync(troubleReportPath, 'utf8');
    return JSON.parse(data);
  } catch (error) {
    logger.error(`Gagal membaca laporan gangguan: ${error.message}`);
    return [];
  }
}

// Mendapatkan laporan gangguan berdasarkan ID
function getTroubleReportById(id) {
  const reports = getAllTroubleReports();
  return reports.find(report => report.id === id);
}

// Mendapatkan laporan gangguan berdasarkan nomor pelanggan
function getTroubleReportsByPhone(phone) {
  const reports = getAllTroubleReports();
  return reports.filter(report => report.phone === phone);
}

// Membuat laporan gangguan baru
function createTroubleReport(reportData) {
  try {
    const reports = getAllTroubleReports();
    
    // Generate ID unik berdasarkan timestamp dan random string
    const id = `TR${Date.now().toString().slice(-6)}${Math.random().toString(36).substring(2, 5).toUpperCase()}`;
    
    const newReport = {
      id,
      status: 'open', // Status awal: open, in_progress, resolved, closed
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
      ...reportData
    };
    
    reports.push(newReport);
    fs.writeFileSync(troubleReportPath, JSON.stringify(reports, null, 2), 'utf8');
    
    // Kirim notifikasi ke grup teknisi jika auto_ticket diaktifkan
    try {
      if (String(getSetting('trouble_report.auto_ticket', 'true')) === 'true') {
        sendNotificationToTechnicians(newReport);
      }
    } catch (notificationError) {
      logger.warn('Failed to send technician notification:', notificationError.message);
    }
    
    return newReport;
  } catch (error) {
    logger.error(`Gagal membuat laporan gangguan: ${error.message}`);
    return null;
  }
}

// Update status laporan gangguan
function updateTroubleReportStatus(id, status, notes, sendNotification = true) {
  try {
    const reports = getAllTroubleReports();
    const reportIndex = reports.findIndex(report => report.id === id);
    
    if (reportIndex === -1) {
      return null;
    }
    
    reports[reportIndex].status = status;
    reports[reportIndex].updatedAt = new Date().toISOString();
    
    if (notes) {
      if (!reports[reportIndex].notes) {
        reports[reportIndex].notes = [];
      }
      
      const noteEntry = {
        timestamp: new Date().toISOString(),
        content: notes,
        status
      };
      
      // Tambahkan flag notifikasi terkirim jika notifikasi akan dikirim
      if (sendNotification) {
        noteEntry.notificationSent = true;
      }
      
      reports[reportIndex].notes.push(noteEntry);
    }
    
    fs.writeFileSync(troubleReportPath, JSON.stringify(reports, null, 2), 'utf8');
    
    // Kirim notifikasi ke pelanggan tentang update status jika sendNotification true
    if (sendNotification) {
      sendStatusUpdateToCustomer(reports[reportIndex]);
      logger.info(`Notifikasi status laporan ${id} terkirim ke pelanggan`);
    } else {
      logger.info(`Update status laporan ${id} tanpa notifikasi ke pelanggan`);
    }
    
    return reports[reportIndex];
  } catch (error) {
    logger.error(`Gagal mengupdate status laporan gangguan: ${error.message}`);
    return null;
  }
}

// Kirim notifikasi ke teknisi dan admin
async function sendNotificationToTechnicians(report) {
  try {
    logger.info(`🔔 Mencoba mengirim notifikasi laporan gangguan ${report.id} ke teknisi dan admin`);
    
    const technicianGroupId = getSetting('technician_group_id', '');
    const companyHeader = getSetting('company_header', 'ALIJAYA DIGITAL NETWORK');
    
    // Format pesan untuk teknisi dan admin
    const message = `🚨 *LAPORAN GANGGUAN BARU*

*${companyHeader}*

📝 *ID Tiket*: ${report.id}
👤 *Pelanggan*: ${report.name || 'N/A'}
📱 *No. HP*: ${report.phone || 'N/A'}
📍 *Lokasi*: ${report.location || 'N/A'}
🔧 *Kategori*: ${report.category || 'N/A'}
🕒 *Waktu Laporan*: ${formatIndonesianDateTime(new Date(report.createdAt))}

💬 *Deskripsi Masalah*:
${report.description || 'Tidak ada deskripsi'}

📌 *Status*: ${report.status.toUpperCase()}

⚠️ *PRIORITAS TINGGI* - Silakan segera ditindaklanjuti!`;

    logger.info(`📝 Pesan yang akan dikirim: ${message.substring(0, 100)}...`);
    
    let sentSuccessfully = false;
    
    // Kirim ke grup teknisi jika ada
    if (technicianGroupId && technicianGroupId !== '') {
      try {
        const result = await sendMessage(technicianGroupId, message);
        if (result) {
          logger.info(`✅ Notifikasi laporan gangguan ${report.id} berhasil terkirim ke grup teknisi`);
          sentSuccessfully = true;
        } else {
          logger.error(`❌ Gagal mengirim notifikasi laporan gangguan ${report.id} ke grup teknisi`);
        }
      } catch (error) {
        logger.error(`❌ Error mengirim ke grup teknisi: ${error.message}`);
      }
    } else {
      logger.warn(`⚠️ Technician group ID kosong, skip pengiriman ke grup`);
    }
    
    // Kirim ke nomor teknisi individual sebagai backup (selalu jalankan)
    const { sendTechnicianMessage } = require('./sendMessage');
    try {
      logger.info(`📤 Mencoba mengirim ke nomor teknisi individual sebagai backup`);
      const techResult = await sendTechnicianMessage(message, 'high');
      if (techResult) {
        logger.info(`✅ Notifikasi laporan gangguan ${report.id} berhasil terkirim ke nomor teknisi`);
        sentSuccessfully = true;
      } else {
        logger.error(`❌ Gagal mengirim notifikasi laporan gangguan ${report.id} ke nomor teknisi`);
      }
    } catch (error) {
      logger.error(`❌ Error mengirim ke nomor teknisi: ${error.message}`);
    }
    
    // Fallback ke admin jika kedua metode diatas gagal
    if (!sentSuccessfully) {
      try {
        logger.info(`📤 Fallback: Mencoba mengirim ke admin`);
        const adminNumber = getSetting('admins.0', '');
        if (adminNumber && adminNumber !== '') {
          const adminMessage = `🚨 *FALLBACK NOTIFICATION*\n\n⚠️ Notifikasi teknisi gagal!\n\n${message}`;
          const adminResult = await sendMessage(adminNumber, adminMessage);
          if (adminResult) {
            logger.info(`✅ Notifikasi laporan gangguan ${report.id} berhasil terkirim ke admin sebagai fallback`);
            sentSuccessfully = true;
          }
        } else {
          logger.warn(`⚠️ Admin number tidak tersedia untuk fallback`);
        }
      } catch (adminError) {
        logger.error(`❌ Error mengirim ke admin fallback: ${adminError.message}`);
      }
    }
    
    // Emergency fallback ke semua admin jika masih gagal
    if (!sentSuccessfully) {
      try {
        logger.info(`📤 Emergency fallback: Mencoba mengirim ke semua admin`);
        let i = 0;
        while (i < 5) { // Max 5 admin numbers
          const adminNumber = getSetting(`admins.${i}`, '');
          if (!adminNumber) break;
          
          try {
            const emergencyMessage = `🆘 *EMERGENCY NOTIFICATION*\n\n❌ Semua teknisi gagal menerima notifikasi!\n\n${message}`;
            const result = await sendMessage(adminNumber, emergencyMessage);
            if (result) {
              logger.info(`✅ Emergency notification berhasil dikirim ke admin ${i}`);
              sentSuccessfully = true;
              break; // Hanya perlu 1 admin yang berhasil
            }
          } catch (e) {
            logger.error(`❌ Gagal mengirim emergency ke admin ${i}: ${e.message}`);
          }
          i++;
        }
      } catch (emergencyError) {
        logger.error(`❌ Error emergency fallback: ${emergencyError.message}`);
      }
    }
    
    // ALWAYS send to admin (parallel notification, tidak tergantung teknisi)
    try {
      logger.info(`📤 Mengirim notifikasi trouble report ke admin (parallel)`);
      
      // Get admin numbers
      let i = 0;
      let adminNotified = false;
      
      while (i < 3) { // Try max 3 admin numbers
        const adminNumber = getSetting(`admins.${i}`, '');
        if (!adminNumber) break;
        
        try {
          const adminMessage = `📋 *LAPORAN GANGGUAN - ADMIN NOTIFICATION*\n\n${message}\n\n💼 *Info Admin*:\nNotifikasi ini dikirim ke admin untuk monitoring dan koordinasi dengan teknisi.`;
          const adminResult = await sendMessage(adminNumber, adminMessage);
          
          if (adminResult) {
            logger.info(`✅ Notifikasi trouble report berhasil dikirim ke admin ${i}`);
            adminNotified = true;
            sentSuccessfully = true;
            break; // Cukup 1 admin yang berhasil
          } else {
            logger.warn(`⚠️ Gagal mengirim ke admin ${i}, coba admin berikutnya`);
          }
        } catch (adminError) {
          logger.error(`❌ Error mengirim ke admin ${i}: ${adminError.message}`);
        }
        i++;
      }
      
      if (!adminNotified) {
        logger.warn(`⚠️ Tidak ada admin yang berhasil menerima notifikasi trouble report`);
      }
      
    } catch (adminError) {
      logger.error(`❌ Error pada admin notification: ${adminError.message}`);
    }
    
    // Log hasil akhir
    if (sentSuccessfully) {
      logger.info(`✅ Notifikasi laporan gangguan ${report.id} berhasil dikirim ke teknisi dan/atau admin`);
    } else {
      logger.error(`❌ CRITICAL: Gagal mengirim notifikasi laporan gangguan ${report.id} ke SEMUA target!`);
    }
    
    return sentSuccessfully;
  } catch (error) {
    logger.error(`❌ Error mengirim notifikasi ke teknisi: ${error.message}`);
    logger.error(`Stack trace: ${error.stack}`);
    return false;
  }
}

// Kirim notifikasi update status ke pelanggan
async function sendStatusUpdateToCustomer(report) {
  try {
    logger.info(`Mencoba mengirim update status laporan ${report.id} ke pelanggan`);
    
    if (!report.phone) {
      logger.warn(`Tidak dapat mengirim update status: nomor pelanggan tidak ada`);
      return false;
    }
    
    const waJid = report.phone.replace(/^0/, '62') + '@s.whatsapp.net';
    logger.info(`WhatsApp JID pelanggan: ${waJid}`);
    
    const companyHeader = getSetting('company_header', 'ISP Monitor');
    
    // Status dalam bahasa Indonesia
    const statusMap = {
      'open': 'Dibuka',
      'in_progress': 'Sedang Ditangani',
      'resolved': 'Terselesaikan',
      'closed': 'Ditutup'
    };
    
    // Ambil catatan terbaru jika ada
    const latestNote = report.notes && report.notes.length > 0 
      ? report.notes[report.notes.length - 1].content 
      : '';
    
    // Format pesan untuk pelanggan
    let message = `📣 *UPDATE LAPORAN GANGGUAN*
    
*${companyHeader}*

📝 *ID Tiket*: ${report.id}
🕒 *Update Pada*: ${formatIndonesianDateTime(new Date(report.updatedAt))}
📌 *Status Baru*: ${statusMap[report.status] || report.status.toUpperCase()}

${latestNote ? `💬 *Catatan Teknisi*:
${latestNote}

` : ''}`;
    
    // Tambahkan instruksi berdasarkan status
    if (report.status === 'open') {
      message += `Laporan Anda telah diterima dan akan segera ditindaklanjuti oleh tim teknisi kami.`;
    } else if (report.status === 'in_progress') {
      message += `Tim teknisi kami sedang menangani laporan Anda. Mohon kesabarannya.`;
    } else if (report.status === 'resolved') {
      message += `✅ Laporan Anda telah diselesaikan. Jika masalah sudah benar-benar teratasi, silakan tutup laporan ini melalui portal pelanggan.

Jika masalah masih berlanjut, silakan tambahkan komentar pada laporan ini.`;
    } else if (report.status === 'closed') {
      message += `🙏 Terima kasih telah menggunakan layanan kami. Laporan ini telah ditutup.`;
    }
    
    message += `

Jika ada pertanyaan, silakan hubungi kami.`;

    logger.info(`Pesan update status yang akan dikirim: ${message.substring(0, 100)}...`);
    
    // Kirim ke pelanggan
    const result = await sendMessage(waJid, message);
    
    if (result) {
      logger.info(`✅ Update status laporan ${report.id} berhasil terkirim ke pelanggan ${report.phone}`);
      return true;
    } else {
      logger.error(`❌ Gagal mengirim update status laporan ${report.id} ke pelanggan ${report.phone}`);
      return false;
    }
  } catch (error) {
    logger.error(`❌ Error mengirim update status ke pelanggan: ${error.message}`);
    logger.error(`Stack trace: ${error.stack}`);
    return false;
  }
}

// Inisialisasi saat modul dimuat
ensureTroubleReportFile();

// Fungsi untuk set sock instance
function setSockInstance(sockInstance) {
  setSock(sockInstance);
}

module.exports = {
  getAllTroubleReports,
  getTroubleReportById,
  getTroubleReportsByPhone,
  createTroubleReport,
  updateTroubleReportStatus,
  sendNotificationToTechnicians,
  sendStatusUpdateToCustomer,
  setSockInstance
};
