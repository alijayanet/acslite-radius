#!/usr/bin/env node

/**
 * Script untuk memperbaiki konfigurasi teknisi
 * Menghapus nomor yang tidak valid dan memperbaiki format
 */

const fs = require('fs');
const path = require('path');

// Load settings
const settingsPath = path.join(__dirname, '..', 'settings.json');
let settings = {};

try {
    settings = JSON.parse(fs.readFileSync(settingsPath, 'utf8'));
    console.log('✅ Settings loaded successfully');
} catch (error) {
    console.error('❌ Error loading settings:', error.message);
    process.exit(1);
}

// Fungsi untuk membersihkan nomor
function cleanPhoneNumber(number) {
    if (!number) return null;
    
    // Hapus semua karakter non-digit
    let cleaned = number.replace(/\D/g, '');
    
    // Jika dimulai dengan 0, ganti dengan 62
    if (cleaned.startsWith('0')) {
        cleaned = '62' + cleaned.substring(1);
    }
    
    // Jika tidak dimulai dengan 62, tambahkan
    if (!cleaned.startsWith('62')) {
        cleaned = '62' + cleaned;
    }
    
    // Pastikan panjang minimal 11 digit (62 + 9 digit nomor)
    if (cleaned.length < 11) {
        return null;
    }
    
    return cleaned;
}

// Fungsi untuk validasi group ID
function validateGroupId(groupId) {
    if (!groupId) return false;
    
    // Format group ID harus: angka@g.us
    const groupIdPattern = /^\d+@g\.us$/;
    return groupIdPattern.test(groupId);
}

// Perbaiki konfigurasi
function fixTechnicianConfig() {
    console.log('\n🔧 Memperbaiki konfigurasi teknisi...\n');
    
    let hasChanges = false;
    
    // Perbaiki nomor teknisi
    const technicianNumbers = [];
    let i = 0;
    
    while (settings[`technician_numbers.${i}`]) {
        const number = settings[`technician_numbers.${i}`];
        const cleanedNumber = cleanPhoneNumber(number);
        
        if (cleanedNumber) {
            technicianNumbers.push(cleanedNumber);
            if (number !== cleanedNumber) {
                console.log(`📞 Fixed technician number ${i + 1}: ${number} → ${cleanedNumber}`);
                hasChanges = true;
            }
        } else {
            console.log(`❌ Removed invalid technician number ${i + 1}: ${number}`);
            hasChanges = true;
        }
        
        i++;
    }
    
    // Update nomor teknisi yang sudah dibersihkan
    technicianNumbers.forEach((number, index) => {
        settings[`technician_numbers.${index}`] = number;
    });
    
    // Hapus nomor yang tidak valid
    let j = technicianNumbers.length;
    while (settings[`technician_numbers.${j}`]) {
        delete settings[`technician_numbers.${j}`];
        j++;
    }
    
    // Perbaiki group ID
    const currentGroupId = settings.technician_group_id;
    if (currentGroupId && !validateGroupId(currentGroupId)) {
        console.log(`❌ Invalid group ID format: ${currentGroupId}`);
        console.log('💡 Group ID harus berformat: 120363029715729111@g.us');
        console.log('💡 Silakan update manual di Admin Settings');
    }
    
    // Perbaiki nomor admin juga
    const adminNumbers = [];
    let k = 0;
    
    // Cek format standard (admins.0) dan format alternatif (admin_numbers.0)
    while (settings[`admins.${k}`] || settings[`admin_numbers.${k}`]) {
        const number = settings[`admins.${k}`] || settings[`admin_numbers.${k}`];
        const cleanedNumber = cleanPhoneNumber(number);
        
        if (cleanedNumber) {
            adminNumbers.push(cleanedNumber);
            if (number !== cleanedNumber) {
                console.log(`📞 Fixed admin number ${k + 1}: ${number} → ${cleanedNumber}`);
                hasChanges = true;
            }
        } else {
            console.log(`❌ Removed invalid admin number ${k + 1}: ${number}`);
            hasChanges = true;
        }
        
        k++;
    }
    
    // Update nomor admin yang sudah dibersihkan (tetap gunakan format admins.index demi kompatibilitas)
    adminNumbers.forEach((number, index) => {
        settings[`admins.${index}`] = number;
        // Hapus format alternatif jika ada
        delete settings[`admin_numbers.${index}`];
    });
    
    // Hapus nomor admin yang tidak valid (format lama dan alternatif)
    let l = adminNumbers.length;
    while (settings[`admins.${l}`] || settings[`admin_numbers.${l}`]) {
        delete settings[`admins.${l}`];
        delete settings[`admin_numbers.${l}`];
        l++;
    }
    
    // Simpan perubahan jika ada
    if (hasChanges) {
        try {
            fs.writeFileSync(settingsPath, JSON.stringify(settings, null, 2));
            console.log('\n✅ Settings berhasil diperbaiki dan disimpan');
        } catch (error) {
            console.error('❌ Error saving settings:', error.message);
            return;
        }
    } else {
        console.log('\n✅ Tidak ada perubahan yang diperlukan');
    }
    
    // Tampilkan ringkasan
    console.log('\n📊 RINGKASAN KONFIGURASI:');
    console.log('========================');
    
    console.log('\n👥 Admin Numbers:');
    if (adminNumbers.length > 0) {
        adminNumbers.forEach((number, index) => {
            console.log(`  ${index + 1}. ${number}`);
        });
    } else {
        console.log('  ❌ Tidak ada nomor admin yang valid');
    }
    
    console.log('\n🔧 Technician Numbers:');
    if (technicianNumbers.length > 0) {
        technicianNumbers.forEach((number, index) => {
            console.log(`  ${index + 1}. ${number}`);
        });
    } else {
        console.log('  ❌ Tidak ada nomor teknisi yang valid');
    }
    
    console.log('\n📱 Technician Group:');
    if (settings.technician_group_id) {
        if (validateGroupId(settings.technician_group_id)) {
            console.log(`  ✅ ${settings.technician_group_id}`);
        } else {
            console.log(`  ❌ ${settings.technician_group_id} (format salah)`);
        }
    } else {
        console.log('  ❌ Tidak dikonfigurasi');
    }
    
    console.log('\n💡 LANGKAH SELANJUTNYA:');
    console.log('1. Restart aplikasi: node app.js');
    console.log('2. Test dengan perintah: checkgroup');
    console.log('3. Pastikan bot sudah ditambahkan ke group teknisi');
    console.log('4. Test pengiriman pesan ke nomor teknisi');
}

// Jalankan perbaikan
if (require.main === module) {
    fixTechnicianConfig();
}

module.exports = { fixTechnicianConfig, cleanPhoneNumber, validateGroupId }; 