const billingManager = require('./billing');
const logger = require('./logger');
const { getSetting } = require('./settingsManager');
const serviceSuspension = require('./serviceSuspension');

class BillingCommands {
    constructor() {
        this.sock = null;
    }

    setSock(sockInstance) {
        this.sock = sockInstance;
    }

    async sendFormattedMessage(remoteJid, message) {
        if (!this.sock) {
            logger.error('WhatsApp sock not initialized');
            return false;
        }

        try {
            const formattedMessage = this.formatWithHeaderFooter(message);
            await this.sock.sendMessage(remoteJid, { text: formattedMessage });
            return true;
        } catch (error) {
            logger.error('Error sending formatted message:', error);
            return false;
        }
    }

    // Suspend layanan pelanggan via WA admin
    async handleIsolir(remoteJid, params) {
        try {
            if (params.length < 1) {
                await this.sendFormattedMessage(remoteJid,
                    '❌ *FORMAT SALAH!*\n\n' +
                    'Format: isolir [nomor/nama_pelanggan] [alasan opsional]\n' +
                    'Contoh:\n' +
                    '• isolir 081234567890 Telat bayar\n' +
                    '• isolir "Santo" Telat 2 bulan'
                );
                return;
            }

            const searchTerm = params[0];
            const reason = params.slice(1).join(' ') || 'Telat bayar (manual WA)';

            // Cari customer berdasarkan phone atau nama (mendukung nama dengan spasi jika parser sudah menggabungkan)
            let customer = await billingManager.getCustomerByNameOrPhone(searchTerm);
            if (!customer) {
                // Coba cari multiple kandidat
                const candidates = await billingManager.findCustomersByNameOrPhone(params.join(' '));
                if (candidates.length === 0) {
                    await this.sendFormattedMessage(remoteJid,
                        '❌ *PELANGGAN TIDAK DITEMUKAN!*\n\n' +
                        `Pencarian: "${params.join(' ')}"`
                    );
                    return;
                }
                if (candidates.length > 1) {
                    let message = `🔍 *DITEMUKAN ${candidates.length} PELANGGAN*\n\n`;
                    candidates.forEach((c, i) => {
                        message += `${i + 1}. *${c.name}*\n`;
                        message += `   📱 ${c.phone}\n`;
                        message += `   👤 ${c.username}\n`;
                        message += `   Gunakan: \`isolir ${c.phone} [alasan]\`\n\n`;
                    });
                    await this.sendFormattedMessage(remoteJid, message);
                    return;
                }
                customer = candidates[0];
            }

            // Jalankan isolir
            const result = await serviceSuspension.suspendCustomerService(customer, reason);
            if (result && result.success) {
                await this.sendFormattedMessage(remoteJid,
                    '⛔ *ISOLIR BERHASIL*\n\n' +
                    `*Pelanggan:* ${customer.name}\n` +
                    `*Nomor:* ${customer.phone}\n` +
                    `*Username:* ${customer.username}\n` +
                    `*Alasan:* ${reason}\n` +
                    `*Status:* Suspended`
                );
            } else {
                await this.sendFormattedMessage(remoteJid,
                    '❌ *GAGAL ISOLIR!*\n\n' +
                    `Error: ${(result && result.error) || 'Unknown error'}`
                );
            }
        } catch (error) {
            logger.error('Error in handleIsolir:', error);
            await this.sendFormattedMessage(remoteJid,
                '❌ *ERROR SISTEM!*\n\n' +
                (error.message || 'Terjadi kesalahan saat isolir pelanggan.')
            );
        }
    }

    // Restore layanan pelanggan via WA admin
    async handleBuka(remoteJid, params) {
        try {
            if (params.length < 1) {
                await this.sendFormattedMessage(remoteJid,
                    '❌ *FORMAT SALAH!*\n\n' +
                    'Format: buka [nomor/nama_pelanggan] [alasan opsional]\n' +
                    'Contoh:\n' +
                    '• buka 081234567890 Sudah bayar\n' +
                    '• buka "Santo" Pembayaran terkonfirmasi'
                );
                return;
            }

            const searchTerm = params[0];
            const reason = params.slice(1).join(' ') || 'Restore layanan (manual WA)';

            let customer = await billingManager.getCustomerByNameOrPhone(searchTerm);
            if (!customer) {
                const candidates = await billingManager.findCustomersByNameOrPhone(params.join(' '));
                if (candidates.length === 0) {
                    await this.sendFormattedMessage(remoteJid,
                        '❌ *PELANGGAN TIDAK DITEMUKAN!*\n\n' +
                        `Pencarian: "${params.join(' ')}"`
                    );
                    return;
                }
                if (candidates.length > 1) {
                    let message = `🔍 *DITEMUKAN ${candidates.length} PELANGGAN*\n\n`;
                    candidates.forEach((c, i) => {
                        message += `${i + 1}. *${c.name}*\n`;
                        message += `   📱 ${c.phone}\n`;
                        message += `   👤 ${c.username}\n`;
                        message += `   Gunakan: \`buka ${c.phone} [alasan]\`\n\n`;
                    });
                    await this.sendFormattedMessage(remoteJid, message);
                    return;
                }
                customer = candidates[0];
            }

            const result = await serviceSuspension.restoreCustomerService(customer, reason);
            if (result && result.success) {
                await this.sendFormattedMessage(remoteJid,
                    '🔓 *RESTORE BERHASIL*\n\n' +
                    `*Pelanggan:* ${customer.name}\n` +
                    `*Nomor:* ${customer.phone}\n` +
                    `*Username:* ${customer.username}\n` +
                    `*Alasan:* ${reason}\n` +
                    `*Status:* Active`
                );
            } else {
                await this.sendFormattedMessage(remoteJid,
                    '❌ *GAGAL RESTORE!*\n\n' +
                    `Error: ${(result && result.error) || 'Unknown error'}`
                );
            }
        } catch (error) {
            logger.error('Error in handleBuka:', error);
            await this.sendFormattedMessage(remoteJid,
                '❌ *ERROR SISTEM!*\n\n' +
                (error.message || 'Terjadi kesalahan saat restore layanan pelanggan.')
            );
        }
    }

    formatWithHeaderFooter(message) {
        const header = getSetting('company_header', 'ALIJAYA BOT MANAGEMENT ISP');
        const footer = getSetting('footer_info', 'Internet Tanpa Batas');
        
        return `🏢 *${header}*\n\n${message}\n\n${footer}`;
    }

    // Menu utama billing
    async handleBillingMenu(remoteJid) {
        const menuMessage = `📊 *MENU BILLING ADMIN*\n\n` +
            `*Perintah Pelanggan:*\n` +
            `• 👤 *tambah [nama] [nomor] [paket]* - Tambah pelanggan baru\n` +
            `• 📝 *edit [nomor] [field] [value]* - Edit data pelanggan\n` +
            `• 🗑️ *hapus [nomor]* - Hapus pelanggan\n` +
            `• 📋 *daftar* - Daftar semua pelanggan\n` +
            `• 🔍 *cari [nomor/nama]* - Cari pelanggan\n\n` +
            
            `*Perintah Pembayaran:*\n` +
            `• 💰 *bayar [nomor/nama]* - Bayar tagihan pelanggan\n` +
            `• 📊 *tagihan [nomor/nama]* - Cek status pembayaran\n` +
            `• ✅ *sudahbayar* - Daftar pelanggan yang sudah bayar\n` +
            `• ⏰ *terlambat* - Daftar pelanggan terlambat\n` +
            `• 📈 *statistik* - Statistik billing\n\n` +

            `*Perintah Isolir:*\n` +
            `• ⛔ *isolir [nomor/nama] [alasan?]* - Suspend layanan pelanggan\n` +
            `• 🔓 *buka [nomor/nama] [alasan?]* - Restore layanan pelanggan\n\n` +
            
            `*Perintah Paket:*\n` +
            `• 📦 *tambahpaket [nama] [speed] [harga]* - Tambah paket\n` +
            `• 📋 *daftarpaket* - Daftar semua paket\n\n` +
            
            `*Perintah Tagihan:*\n` +
            `• 📄 *buattagihan [nomor] [jumlah] [tanggal]* - Buat tagihan\n` +
            `• 📊 *daftartagihan [nomor]* - Daftar tagihan pelanggan\n\n` +
            
            `*Contoh Penggunaan:*\n` +
            `tambah "John Doe" 081234567890 "Paket Premium"\n` +
            `bayar 081321960111  ← menggunakan nomor\n` +
            `bayar Santo  ← menggunakan nama\n` +
            `tagihan "John Doe"  ← nama dengan spasi\n` +
            `cari John  ← pencarian nama\n` +
            `isolir Santo Telat bayar 2 bulan\n` +
            `buka 081234567890 Sudah melunasi tagihan\n` +
            `sudahbayar`;

        await this.sendFormattedMessage(remoteJid, menuMessage);
    }

    // Customer Management Commands
    async handleAddCustomer(remoteJid, params) {
        try {
            if (params.length < 3) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *FORMAT SALAH!*\n\n' +
                    'Format: addcustomer [nama] [phone] [paket]\n' +
                    'Contoh: addcustomer "John Doe" 081234567890 "Paket Premium"'
                );
                return;
            }

            const name = params[0];
            const phone = params[1].replace(/\D/g, '');
            const packageName = params[2];

            // Cek apakah paket ada
            const packages = await billingManager.getPackages();
            const selectedPackage = packages.find(p => p.name.toLowerCase().includes(packageName.toLowerCase()));
            
            if (!selectedPackage) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *PAKET TIDAK DITEMUKAN!*\n\n' +
                    'Paket yang tersedia:\n' +
                    packages.map(p => `• ${p.name} - ${p.speed} - Rp${p.price}`).join('\n')
                );
                return;
            }

            // Cek apakah phone sudah ada
            const existingCustomer = await billingManager.getCustomerByPhone(phone);
            if (existingCustomer) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *NOMOR TELEPON SUDAH TERDAFTAR!*\n\n' +
                    `Pelanggan: ${existingCustomer.name}`
                );
                return;
            }

            const customerData = {
                name: name,
                phone: phone,
                package_id: selectedPackage.id,
                pppoe_username: billingManager.generatePPPoEUsername(phone),
                status: 'active'
            };

            const result = await billingManager.createCustomer(customerData);
            
            if (result.success) {
                await this.sendFormattedMessage(remoteJid, 
                    '✅ *PELANGGAN BERHASIL DITAMBAHKAN!*\n\n' +
                    `*Nama:* ${name}\n` +
                    `*Phone:* ${phone}\n` +
                    `*Paket:* ${selectedPackage.name} (${selectedPackage.speed})\n` +
                    `*Username PPPoE:* ${customerData.pppoe_username}\n` +
                    `*Harga:* Rp${selectedPackage.price}/bulan`
                );
            } else {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *GAGAL MENAMBAHKAN PELANGGAN!*\n\n' +
                    `Error: ${result.error}`
                );
            }
        } catch (error) {
            logger.error('Error in handleAddCustomer:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat menambah pelanggan.'
            );
        }
    }

    // Tambah pelanggan (bahasa Indonesia)
    async handleTambah(remoteJid, params) {
        try {
            if (params.length < 3) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *FORMAT SALAH!*\n\n' +
                    'Format: tambah [nama] [nomor] [paket]\n' +
                    'Contoh: tambah "John Doe" 081234567890 "Paket Premium"'
                );
                return;
            }

            const name = params[0];
            const phone = params[1].replace(/\D/g, '');
            const packageName = params[2];

            // Cek apakah paket ada
            const packages = await billingManager.getPackages();
            const selectedPackage = packages.find(p => p.name.toLowerCase().includes(packageName.toLowerCase()));
            
            if (!selectedPackage) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *PAKET TIDAK DITEMUKAN!*\n\n' +
                    'Paket yang tersedia:\n' +
                    packages.map(p => `• ${p.name} - ${p.speed} - Rp${p.price}`).join('\n')
                );
                return;
            }

            // Cek apakah phone sudah ada
            const existingCustomer = await billingManager.getCustomerByPhone(phone);
            if (existingCustomer) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *NOMOR TELEPON SUDAH TERDAFTAR!*\n\n' +
                    `Pelanggan: ${existingCustomer.name}`
                );
                return;
            }

            const customerData = {
                name: name,
                phone: phone,
                package_id: selectedPackage.id,
                pppoe_username: billingManager.generatePPPoEUsername(phone),
                status: 'active'
            };

            const result = await billingManager.createCustomer(customerData);
            
            if (result.success) {
                await this.sendFormattedMessage(remoteJid, 
                    '✅ *PELANGGAN BERHASIL DITAMBAHKAN!*\n\n' +
                    `*Nama:* ${name}\n` +
                    `*Nomor:* ${phone}\n` +
                    `*Paket:* ${selectedPackage.name} (${selectedPackage.speed})\n` +
                    `*Username PPPoE:* ${customerData.pppoe_username}\n` +
                    `*Harga:* Rp${selectedPackage.price}/bulan`
                );
            } else {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *GAGAL MENAMBAHKAN PELANGGAN!*\n\n' +
                    `Error: ${result.error}`
                );
            }
        } catch (error) {
            logger.error('Error in handleTambah:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat menambah pelanggan.'
            );
        }
    }

    async handleEditCustomer(remoteJid, params) {
        try {
            if (params.length < 3) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *FORMAT SALAH!*\n\n' +
                    'Format: editcustomer [phone] [field] [value]\n' +
                    'Field yang tersedia: name, phone, package_id, status\n' +
                    'Contoh: editcustomer 081234567890 name "John Smith"'
                );
                return;
            }

            const phone = params[0].replace(/\D/g, '');
            const field = params[1];
            const value = params[2];

            const customer = await billingManager.getCustomerByPhone(phone);
            if (!customer) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *PELANGGAN TIDAK DITEMUKAN!*\n\n' +
                    `Phone: ${phone}`
                );
                return;
            }

            const updateData = {};
            updateData[field] = value;

            const result = await billingManager.updateCustomer(phone, updateData);
            
            if (result.success) {
                await this.sendFormattedMessage(remoteJid, 
                    '✅ *DATA PELANGGAN BERHASIL DIUPDATE!*\n\n' +
                    `*Phone:* ${phone}\n` +
                    `*Field:* ${field}\n` +
                    `*Value:* ${value}`
                );
            } else {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *GAGAL UPDATE DATA PELANGGAN!*\n\n' +
                    `Error: ${result.error}`
                );
            }
        } catch (error) {
            logger.error('Error in handleEditCustomer:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat mengupdate data pelanggan.'
            );
        }
    }

    async handleDeleteCustomer(remoteJid, params) {
        try {
            if (params.length < 1) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *FORMAT SALAH!*\n\n' +
                    'Format: delcustomer [phone]\n' +
                    'Contoh: delcustomer 081234567890'
                );
                return;
            }

            const phone = params[0].replace(/\D/g, '');
            const customer = await billingManager.getCustomerByPhone(phone);
            
            if (!customer) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *PELANGGAN TIDAK DITEMUKAN!*\n\n' +
                    `Phone: ${phone}`
                );
                return;
            }

            const result = await billingManager.deleteCustomer(phone);
            
            if (result.success) {
                await this.sendFormattedMessage(remoteJid, 
                    '✅ *PELANGGAN BERHASIL DIHAPUS!*\n\n' +
                    `*Nama:* ${customer.name}\n` +
                    `*Phone:* ${customer.phone}\n` +
                    `*Username:* ${customer.username}`
                );
            } else {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *GAGAL MENGHAPUS PELANGGAN!*\n\n' +
                    `Error: ${result.error}`
                );
            }
        } catch (error) {
            logger.error('Error in handleDeleteCustomer:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat menghapus pelanggan.'
            );
        }
    }

    async handleListCustomers(remoteJid) {
        try {
            const customers = await billingManager.getCustomers();
            
            if (customers.length === 0) {
                await this.sendFormattedMessage(remoteJid, 
                    '📋 *DAFTAR PELANGGAN*\n\n' +
                    'Tidak ada pelanggan terdaftar.'
                );
                return;
            }

            let message = `📋 *DAFTAR PELANGGAN* (${customers.length} total)\n\n`;
            
            customers.forEach((customer, index) => {
                message += `${index + 1}. *${customer.name}*\n`;
                message += `   📱 ${customer.phone}\n`;
                message += `   👤 ${customer.username}\n`;
                message += `   📦 Paket: ${customer.package_name || 'N/A'}\n`;
                message += `   📊 Status: ${customer.status}\n\n`;
            });

            await this.sendFormattedMessage(remoteJid, message);
        } catch (error) {
            logger.error('Error in handleListCustomers:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat mengambil daftar pelanggan.'
            );
        }
    }

    // Daftar pelanggan (bahasa Indonesia)
    async handleDaftar(remoteJid) {
        try {
            const customers = await billingManager.getCustomers();
            
            if (customers.length === 0) {
                await this.sendFormattedMessage(remoteJid, 
                    '📋 *DAFTAR PELANGGAN*\n\n' +
                    'Tidak ada pelanggan terdaftar.'
                );
                return;
            }

            let message = `📋 *DAFTAR PELANGGAN* (${customers.length} total)\n\n`;
            
            customers.forEach((customer, index) => {
                message += `${index + 1}. *${customer.name}*\n`;
                message += `   📱 ${customer.phone}\n`;
                message += `   👤 ${customer.username}\n`;
                message += `   📦 Paket: ${customer.package_name || 'N/A'}\n`;
                message += `   📊 Status: ${customer.status}\n\n`;
            });

            await this.sendFormattedMessage(remoteJid, message);
        } catch (error) {
            logger.error('Error in handleDaftar:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat mengambil daftar pelanggan.'
            );
        }
    }

    async handleFindCustomer(remoteJid, params) {
        try {
            if (params.length < 1) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *FORMAT SALAH!*\n\n' +
                    'Format: findcustomer [phone/username]\n' +
                    'Contoh: findcustomer 081234567890'
                );
                return;
            }

            const searchTerm = params[0];
            const customers = await billingManager.getCustomers();
            
            // Cari berdasarkan phone atau username
            const customer = customers.find(c => 
                c.phone.includes(searchTerm) || 
                c.username.toLowerCase().includes(searchTerm.toLowerCase()) ||
                c.name.toLowerCase().includes(searchTerm.toLowerCase())
            );

            if (!customer) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *PELANGGAN TIDAK DITEMUKAN!*\n\n' +
                    `Search term: ${searchTerm}`
                );
                return;
            }

            const packages = await billingManager.getPackages();
            const selectedPackage = packages.find(p => p.id === customer.package_id);

            let message = `🔍 *DETAIL PELANGGAN*\n\n`;
            message += `*Nama:* ${customer.name}\n`;
            message += `*Phone:* ${customer.phone}\n`;
            message += `*Username:* ${customer.username}\n`;
            message += `*PPPoE Username:* ${customer.pppoe_username || 'N/A'}\n`;
            message += `*Paket:* ${selectedPackage ? selectedPackage.name : 'N/A'}\n`;
            message += `*Speed:* ${selectedPackage ? selectedPackage.speed : 'N/A'}\n`;
            message += `*Harga:* ${selectedPackage ? `Rp${selectedPackage.price}` : 'N/A'}\n`;
            message += `*Status:* ${customer.status}\n`;
            message += `*Join Date:* ${customer.join_date}`;

            await this.sendFormattedMessage(remoteJid, message);
        } catch (error) {
            logger.error('Error in handleFindCustomer:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat mencari pelanggan.'
            );
        }
    }

    // Cari pelanggan (bahasa Indonesia)
    async handleCari(remoteJid, params) {
        try {
            if (params.length < 1) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *FORMAT SALAH!*\n\n' +
                    'Format: cari [nomor/nama_pelanggan]\n' +
                    'Contoh: \n' +
                    '• cari 081234567890\n' +
                    '• cari "Santo"\n' +
                    '• cari John'
                );
                return;
            }

            const searchTerm = params.join(' '); // Gabungkan semua params untuk nama yang mengandung spasi
            
            // Coba cari berdasarkan nomor atau nama
            let customer = await billingManager.getCustomerByNameOrPhone(searchTerm);
            
            // Jika tidak ditemukan dengan pencarian tunggal, coba cari multiple
            if (!customer) {
                const customers = await billingManager.findCustomersByNameOrPhone(searchTerm);
                
                if (customers.length === 0) {
                    await this.sendFormattedMessage(remoteJid, 
                        '❌ *PELANGGAN TIDAK DITEMUKAN!*\n\n' +
                        `Pencarian: "${searchTerm}"\n` +
                        `Pastikan nomor telepon atau nama pelanggan benar.`
                    );
                    return;
                } else if (customers.length === 1) {
                    customer = customers[0];
                } else {
                    // Multiple customers found, show all
                    let message = `🔍 *DITEMUKAN ${customers.length} PELANGGAN*\n\n`;
                    message += `Pencarian: "${searchTerm}"\n\n`;
                    
                    customers.forEach((cust, index) => {
                        message += `${index + 1}. *${cust.name}*\n`;
                        message += `   📱 ${cust.phone}\n`;
                        message += `   👤 ${cust.username}\n`;
                        message += `   📦 ${cust.package_name || 'N/A'}\n`;
                        message += `   📊 Status: ${cust.status}\n\n`;
                    });
                    
                    message += `Gunakan nomor telepon untuk detail lebih lanjut:\n`;
                    message += `Contoh: \`cari ${customers[0].phone}\``;
                    
                    await this.sendFormattedMessage(remoteJid, message);
                    return;
                }
            }

            const packages = await billingManager.getPackages();
            const selectedPackage = packages.find(p => p.id === customer.package_id);

            let message = `🔍 *DETAIL PELANGGAN*\n\n`;
            message += `*Nama:* ${customer.name}\n`;
            message += `*Nomor:* ${customer.phone}\n`;
            message += `*Username:* ${customer.username}\n`;
            message += `*PPPoE Username:* ${customer.pppoe_username || 'N/A'}\n`;
            message += `*Paket:* ${selectedPackage ? selectedPackage.name : 'N/A'}\n`;
            message += `*Speed:* ${selectedPackage ? selectedPackage.speed : 'N/A'}\n`;
            message += `*Harga:* ${selectedPackage ? `Rp${selectedPackage.price.toLocaleString()}` : 'N/A'}\n`;
            message += `*Status:* ${customer.status}\n`;
            message += `*Tanggal Bergabung:* ${customer.join_date}`;

            await this.sendFormattedMessage(remoteJid, message);
        } catch (error) {
            logger.error('Error in handleCari:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat mencari pelanggan.'
            );
        }
    }

    // Payment Management Commands
    async handlePayInvoice(remoteJid, params) {
        try {
            if (params.length < 3) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *FORMAT SALAH!*\n\n' +
                    'Format: payinvoice [invoice_id] [amount] [method]\n' +
                    'Contoh: payinvoice 123 500000 cash'
                );
                return;
            }

            const invoiceId = parseInt(params[0]);
            const amount = parseFloat(params[1]);
            const method = params[2];

            const invoice = await billingManager.getInvoiceById(invoiceId);
            if (!invoice) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *INVOICE TIDAK DITEMUKAN!*\n\n' +
                    `Invoice ID: ${invoiceId}`
                );
                return;
            }

            const customer = await billingManager.getCustomerById(invoice.customer_id);
            const selectedPackage = await billingManager.getPackageById(invoice.package_id);

            const paymentData = {
                invoice_id: invoiceId,
                amount: amount,
                payment_method: method,
                reference_number: `WHATSAPP_${Date.now()}`,
                notes: 'Payment via WhatsApp Admin'
            };

            const result = await billingManager.recordPayment(paymentData);
            
            if (result.success) {
                // Update invoice status
                await billingManager.updateInvoiceStatus(invoiceId, 'paid', method);

                await this.sendFormattedMessage(remoteJid, 
                    '✅ *PEMBAYARAN BERHASIL!*\n\n' +
                    `*Invoice ID:* ${invoiceId}\n` +
                    `*Pelanggan:* ${customer.name}\n` +
                    `*Paket:* ${selectedPackage.name}\n` +
                    `*Amount:* Rp${amount.toLocaleString()}\n` +
                    `*Method:* ${method}\n` +
                    `*Status:* Paid`
                );

                // Auto-restore jika semua tagihan lunas
                try {
                    const refreshed = await billingManager.getCustomerById(customer.id);
                    const allInvoices = await billingManager.getInvoicesByCustomer(customer.id);
                    const unpaid = allInvoices.filter(i => i.status === 'unpaid');
                    logger.info(`[BILLING][WA] PayInvoice cek auto-restore -> status: ${refreshed?.status}, unpaid: ${unpaid.length}`);
                    if (refreshed && refreshed.status === 'suspended' && unpaid.length === 0) {
                        logger.info('[BILLING][WA] PayInvoice tidak ada tagihan tertunda. Menjalankan restore layanan...');
                        const restoreRes = await serviceSuspension.restoreCustomerService(refreshed, `Payment via WhatsApp (${method})`);
                        logger.info('[BILLING][WA] PayInvoice hasil restore:', restoreRes);
                    }
                } catch (restoreErr) {
                    logger.error('[BILLING][WA] PayInvoice gagal auto-restore setelah pembayaran:', restoreErr);
                }
            } else {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *GAGAL MEMPROSES PEMBAYARAN!*\n\n' +
                    `Error: ${result.error}`
                );
            }
        } catch (error) {
            logger.error('Error in handlePayInvoice:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat memproses pembayaran.'
            );
        }
    }

    // Pembayaran sederhana dengan nomor pelanggan atau nama
    async handleBayar(remoteJid, params) {
        try {
            logger.info(`[BILLING] handleBayar dipanggil dengan params:`, params);
            
            if (params.length < 1) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *FORMAT SALAH!*\n\n' +
                    'Format: bayar [nomor/nama_pelanggan]\n' +
                    'Contoh: \n' +
                    '• bayar 081234567890\n' +
                    '• bayar "Santo"\n' +
                    '• bayar John'
                );
                return;
            }

            const searchTerm = params.join(' '); // Gabungkan semua params untuk nama yang mengandung spasi
            logger.info(`[BILLING] Mencari pelanggan dengan: ${searchTerm}`);
            
            // Coba cari berdasarkan nomor atau nama
            let customer = await billingManager.getCustomerByNameOrPhone(searchTerm);
            logger.info(`[BILLING] Customer ditemukan:`, customer ? 'Ya' : 'Tidak');
            
            // Jika tidak ditemukan dengan pencarian tunggal, coba cari multiple dan tanya konfirmasi
            if (!customer) {
                const customers = await billingManager.findCustomersByNameOrPhone(searchTerm);
                logger.info(`[BILLING] Multiple customers found: ${customers.length}`);
                
                if (customers.length === 0) {
                    await this.sendFormattedMessage(remoteJid, 
                        '❌ *PELANGGAN TIDAK DITEMUKAN!*\n\n' +
                        `Pencarian: "${searchTerm}"\n` +
                        `Pastikan nomor telepon atau nama pelanggan benar.`
                    );
                    return;
                } else if (customers.length === 1) {
                    customer = customers[0];
                } else {
                    // Multiple customers found, ask for clarification
                    let message = `🔍 *DITEMUKAN ${customers.length} PELANGGAN*\n\n`;
                    message += `Pencarian: "${searchTerm}"\n\n`;
                    message += `Silakan gunakan perintah bayar dengan data yang lebih spesifik:\n\n`;
                    
                    customers.forEach((cust, index) => {
                        message += `${index + 1}. *${cust.name}*\n`;
                        message += `   📱 ${cust.phone}\n`;
                        message += `   📦 ${cust.package_name || 'N/A'}\n`;
                        message += `   Gunakan: \`bayar ${cust.phone}\`\n\n`;
                    });
                    
                    await this.sendFormattedMessage(remoteJid, message);
                    return;
                }
            }

            // Cari invoice yang belum dibayar
            logger.info(`[BILLING] Mencari invoice untuk customer ID: ${customer.id}`);
            const invoices = await billingManager.getInvoicesByCustomer(customer.id);
            logger.info(`[BILLING] Total invoice ditemukan: ${invoices ? invoices.length : 0}`);
            
            if (!invoices || invoices.length === 0) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *PELANGGAN TIDAK MEMILIKI TAGIHAN!*\n\n' +
                    `*Pelanggan:* ${customer.name}\n` +
                    `*Nomor:* ${customer.phone}\n` +
                    `*Status:* Belum ada tagihan dibuat`
                );
                return;
            }
            
            const unpaidInvoices = invoices.filter(inv => inv.status === 'unpaid');
            logger.info(`[BILLING] Invoice belum dibayar: ${unpaidInvoices.length}`);
            
            if (unpaidInvoices.length === 0) {
                await this.sendFormattedMessage(remoteJid, 
                    '✅ *PELANGGAN TIDAK MEMILIKI TAGIHAN!*\n\n' +
                    `*Pelanggan:* ${customer.name}\n` +
                    `*Nomor:* ${customer.phone}\n` +
                    `*Status:* Semua tagihan sudah dibayar`
                );
                return;
            }

            // Ambil invoice terlama yang belum dibayar
            const oldestInvoice = unpaidInvoices.sort((a, b) => new Date(a.due_date) - new Date(b.due_date))[0];
            logger.info(`[BILLING] Invoice terpilih:`, oldestInvoice.id);
            
            const selectedPackage = await billingManager.getPackageById(oldestInvoice.package_id);
            logger.info(`[BILLING] Package ditemukan:`, selectedPackage ? 'Ya' : 'Tidak');

            if (!selectedPackage) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *ERROR PAKET!*\n\n' +
                    'Paket tidak ditemukan untuk invoice ini.'
                );
                return;
            }

            const paymentData = {
                invoice_id: oldestInvoice.id,
                amount: oldestInvoice.amount,
                payment_method: 'cash',
                reference_number: `WHATSAPP_${Date.now()}`,
                notes: 'Payment via WhatsApp Admin'
            };

            logger.info(`[BILLING] Memproses pembayaran:`, paymentData);
            
            try {
                const result = await billingManager.recordPayment(paymentData);
                logger.info(`[BILLING] Hasil record payment:`, result);
                
                if (result && result.success) {
                    // Update invoice status
                    logger.info(`[BILLING] Mengupdate status invoice: ${oldestInvoice.id}`);
                    await billingManager.updateInvoiceStatus(oldestInvoice.id, 'paid', 'cash');

                    await this.sendFormattedMessage(remoteJid, 
                        '✅ *PEMBAYARAN BERHASIL!*\n\n' +
                        `*Pelanggan:* ${customer.name}\n` +
                        `*Nomor:* ${customer.phone}\n` +
                        `*Paket:* ${selectedPackage.name}\n` +
                        `*Tagihan:* ${oldestInvoice.invoice_number}\n` +
                        `*Jumlah:* Rp${oldestInvoice.amount.toLocaleString()}\n` +
                        `*Metode:* Cash\n` +
                        `*Status:* Lunas`
                    );

                    // Coba auto-restore layanan jika semua tagihan sudah lunas
                    try {
                        const refreshed = await billingManager.getCustomerById(customer.id);
                        const allInvoices = await billingManager.getInvoicesByCustomer(customer.id);
                        const unpaid = allInvoices.filter(i => i.status === 'unpaid');
                        logger.info(`[BILLING][WA] Cek auto-restore -> status: ${refreshed?.status}, unpaid: ${unpaid.length}`);
                        if (refreshed && refreshed.status === 'suspended' && unpaid.length === 0) {
                            logger.info('[BILLING][WA] Tidak ada tagihan tertunda. Menjalankan restore layanan...');
                            const restoreRes = await serviceSuspension.restoreCustomerService(refreshed, 'Payment via WhatsApp Admin');
                            logger.info('[BILLING][WA] Hasil restore:', restoreRes);
                        }
                    } catch (restoreErr) {
                        logger.error('[BILLING][WA] Gagal auto-restore setelah pembayaran:', restoreErr);
                    }
                } else {
                    logger.error(`[BILLING] Record payment gagal:`, result);
                    await this.sendFormattedMessage(remoteJid, 
                        '❌ *GAGAL MEMPROSES PEMBAYARAN!*\n\n' +
                        `Error: ${result ? result.error : 'Payment record failed'}`
                    );
                }
            } catch (paymentError) {
                logger.error(`[BILLING] Error saat record payment:`, paymentError);
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *GAGAL MEMPROSES PEMBAYARAN!*\n\n' +
                    `Error: ${paymentError.message || 'Database error'}`
                );
            }
        } catch (error) {
            logger.error('Error in handleBayar:', error);
            logger.error('Error stack:', error.stack);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                `Terjadi kesalahan: ${error.message || 'Unknown error'}`
            );
        }
    }

    async handleCheckPayment(remoteJid, params) {
        try {
            if (params.length < 1) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *FORMAT SALAH!*\n\n' +
                    'Format: checkpayment [invoice_id]\n' +
                    'Contoh: checkpayment 123'
                );
                return;
            }

            const invoiceId = parseInt(params[0]);
            const invoice = await billingManager.getInvoiceById(invoiceId);
            
            if (!invoice) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *INVOICE TIDAK DITEMUKAN!*\n\n' +
                    `Invoice ID: ${invoiceId}`
                );
                return;
            }

            const customer = await billingManager.getCustomerById(invoice.customer_id);
            const selectedPackage = await billingManager.getPackageById(invoice.package_id);
            const payments = await billingManager.getPayments(invoiceId);

            let message = `📊 *STATUS PEMBAYARAN*\n\n`;
            message += `*Invoice ID:* ${invoice.id}\n`;
            message += `*Invoice Number:* ${invoice.invoice_number}\n`;
            message += `*Pelanggan:* ${customer.name}\n`;
            message += `*Phone:* ${customer.phone}\n`;
            message += `*Paket:* ${selectedPackage.name}\n`;
            message += `*Amount:* Rp${invoice.amount.toLocaleString()}\n`;
            message += `*Due Date:* ${invoice.due_date}\n`;
            message += `*Status:* ${invoice.status.toUpperCase()}\n\n`;

            if (payments.length > 0) {
                message += `*Payment History:*\n`;
                payments.forEach((payment, index) => {
                    message += `${index + 1}. Rp${payment.amount.toLocaleString()} - ${payment.payment_method} - ${payment.payment_date}\n`;
                });
            } else {
                message += `*Payment History:* Belum ada pembayaran`;
            }

            await this.sendFormattedMessage(remoteJid, message);
        } catch (error) {
            logger.error('Error in handleCheckPayment:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat mengecek status pembayaran.'
            );
        }
    }

    // Cek status pembayaran dengan nomor pelanggan atau nama
    async handleTagihan(remoteJid, params) {
        try {
            if (params.length < 1) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *FORMAT SALAH!*\n\n' +
                    'Format: tagihan [nomor/nama_pelanggan]\n' +
                    'Contoh: \n' +
                    '• tagihan 081234567890\n' +
                    '• tagihan "Santo"\n' +
                    '• tagihan John'
                );
                return;
            }

            const searchTerm = params.join(' '); // Gabungkan semua params untuk nama yang mengandung spasi
            
            // Coba cari berdasarkan nomor atau nama
            let customer = await billingManager.getCustomerByNameOrPhone(searchTerm);
            
            // Jika tidak ditemukan dengan pencarian tunggal, coba cari multiple dan tanya konfirmasi
            if (!customer) {
                const customers = await billingManager.findCustomersByNameOrPhone(searchTerm);
                
                if (customers.length === 0) {
                    await this.sendFormattedMessage(remoteJid, 
                        '❌ *PELANGGAN TIDAK DITEMUKAN!*\n\n' +
                        `Pencarian: "${searchTerm}"\n` +
                        `Pastikan nomor telepon atau nama pelanggan benar.`
                    );
                    return;
                } else if (customers.length === 1) {
                    customer = customers[0];
                } else {
                    // Multiple customers found, ask for clarification
                    let message = `🔍 *DITEMUKAN ${customers.length} PELANGGAN*\n\n`;
                    message += `Pencarian: "${searchTerm}"\n\n`;
                    message += `Silakan gunakan perintah tagihan dengan data yang lebih spesifik:\n\n`;
                    
                    customers.forEach((cust, index) => {
                        message += `${index + 1}. *${cust.name}*\n`;
                        message += `   📱 ${cust.phone}\n`;
                        message += `   📦 ${cust.package_name || 'N/A'}\n`;
                        message += `   Gunakan: \`tagihan ${cust.phone}\`\n\n`;
                    });
                    
                    await this.sendFormattedMessage(remoteJid, message);
                    return;
                }
            }

            const invoices = await billingManager.getInvoicesByCustomer(customer.id);
            const selectedPackage = await billingManager.getPackages().then(packages => 
                packages.find(p => p.id === customer.package_id)
            );

            let message = `📊 *STATUS PELANGGAN*\n\n`;
            message += `*Nama:* ${customer.name}\n`;
            message += `*Nomor:* ${customer.phone}\n`;
            message += `*Paket:* ${selectedPackage ? selectedPackage.name : 'N/A'}\n`;
            message += `*Status:* ${customer.status}\n\n`;

            if (invoices.length === 0) {
                message += `*Tagihan:* Belum ada tagihan`;
            } else {
                const unpaidInvoices = invoices.filter(inv => inv.status === 'unpaid');
                const paidInvoices = invoices.filter(inv => inv.status === 'paid');
                
                message += `*Total Tagihan:* ${invoices.length}\n`;
                message += `*Sudah Bayar:* ${paidInvoices.length}\n`;
                message += `*Belum Bayar:* ${unpaidInvoices.length}\n\n`;

                if (unpaidInvoices.length > 0) {
                    message += `*Tagihan Belum Dibayar:*\n`;
                    unpaidInvoices.slice(0, 3).forEach((invoice, index) => {
                        const dueDate = new Date(invoice.due_date);
                        const today = new Date();
                        const daysOverdue = Math.floor((today - dueDate) / (1000 * 60 * 60 * 24));
                        
                        message += `${index + 1}. ${invoice.invoice_number}\n`;
                        message += `   💰 Rp${invoice.amount.toLocaleString()}\n`;
                        message += `   📅 Jatuh tempo: ${invoice.due_date}\n`;
                        message += `   ⏰ ${daysOverdue > 0 ? `${daysOverdue} hari terlambat` : 'Belum terlambat'}\n\n`;
                    });
                    
                    if (unpaidInvoices.length > 3) {
                        message += `... dan ${unpaidInvoices.length - 3} tagihan lainnya`;
                    }
                }
            }

            await this.sendFormattedMessage(remoteJid, message);
        } catch (error) {
            logger.error('Error in handleTagihan:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat mengecek status pelanggan.'
            );
        }
    }

    async handlePaidCustomers(remoteJid) {
        try {
            const invoices = await billingManager.getInvoices();
            const paidInvoices = invoices.filter(inv => inv.status === 'paid');
            
            if (paidInvoices.length === 0) {
                await this.sendFormattedMessage(remoteJid, 
                    '✅ *PELANGGAN YANG SUDAH BAYAR*\n\n' +
                    'Tidak ada pelanggan yang sudah membayar.'
                );
                return;
            }

            let message = `✅ *PELANGGAN YANG SUDAH BAYAR* (${paidInvoices.length} total)\n\n`;
            
            for (const invoice of paidInvoices.slice(0, 10)) { // Limit to 10
                const customer = await billingManager.getCustomerById(invoice.customer_id);
                const selectedPackage = await billingManager.getPackageById(invoice.package_id);
                
                message += `• *${customer.name}*\n`;
                message += `  📱 ${customer.phone}\n`;
                message += `  📦 ${selectedPackage.name}\n`;
                message += `  💰 Rp${invoice.amount.toLocaleString()}\n`;
                message += `  📅 ${invoice.payment_date}\n\n`;
            }

            if (paidInvoices.length > 10) {
                message += `... dan ${paidInvoices.length - 10} pelanggan lainnya`;
            }

            await this.sendFormattedMessage(remoteJid, message);
        } catch (error) {
            logger.error('Error in handlePaidCustomers:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat mengambil daftar pelanggan yang sudah bayar.'
            );
        }
    }

    // Daftar pelanggan yang sudah bayar (bahasa Indonesia)
    async handleSudahBayar(remoteJid) {
        try {
            const invoices = await billingManager.getInvoices();
            const paidInvoices = invoices.filter(inv => inv.status === 'paid');
            
            if (paidInvoices.length === 0) {
                await this.sendFormattedMessage(remoteJid, 
                    '✅ *PELANGGAN YANG SUDAH BAYAR*\n\n' +
                    'Tidak ada pelanggan yang sudah membayar.'
                );
                return;
            }

            let message = `✅ *PELANGGAN YANG SUDAH BAYAR* (${paidInvoices.length} total)\n\n`;
            
            for (const invoice of paidInvoices.slice(0, 10)) { // Limit to 10
                const customer = await billingManager.getCustomerById(invoice.customer_id);
                const selectedPackage = await billingManager.getPackageById(invoice.package_id);
                
                message += `• *${customer.name}*\n`;
                message += `  📱 ${customer.phone}\n`;
                message += `  📦 ${selectedPackage.name}\n`;
                message += `  💰 Rp${invoice.amount.toLocaleString()}\n`;
                message += `  📅 ${invoice.payment_date}\n\n`;
            }

            if (paidInvoices.length > 10) {
                message += `... dan ${paidInvoices.length - 10} pelanggan lainnya`;
            }

            await this.sendFormattedMessage(remoteJid, message);
        } catch (error) {
            logger.error('Error in handleSudahBayar:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat mengambil daftar pelanggan yang sudah bayar.'
            );
        }
    }

    async handleOverdueCustomers(remoteJid) {
        try {
            const overdueInvoices = await billingManager.getOverdueInvoices();
            
            if (overdueInvoices.length === 0) {
                await this.sendFormattedMessage(remoteJid, 
                    '⏰ *PELANGGAN TERLAMBAT BAYAR*\n\n' +
                    'Tidak ada pelanggan yang terlambat bayar.'
                );
                return;
            }

            let message = `⏰ *PELANGGAN TERLAMBAT BAYAR* (${overdueInvoices.length} total)\n\n`;
            
            for (const invoice of overdueInvoices.slice(0, 10)) { // Limit to 10
                const customer = await billingManager.getCustomerById(invoice.customer_id);
                const selectedPackage = await billingManager.getPackageById(invoice.package_id);
                
                const dueDate = new Date(invoice.due_date);
                const today = new Date();
                const daysOverdue = Math.floor((today - dueDate) / (1000 * 60 * 60 * 24));
                
                message += `• *${customer.name}*\n`;
                message += `  📱 ${customer.phone}\n`;
                message += `  📦 ${selectedPackage.name}\n`;
                message += `  💰 Rp${invoice.amount.toLocaleString()}\n`;
                message += `  📅 Due: ${invoice.due_date}\n`;
                message += `  ⏰ ${daysOverdue} hari terlambat\n\n`;
            }

            if (overdueInvoices.length > 10) {
                message += `... dan ${overdueInvoices.length - 10} pelanggan lainnya`;
            }

            await this.sendFormattedMessage(remoteJid, message);
        } catch (error) {
            logger.error('Error in handleOverdueCustomers:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat mengambil daftar pelanggan terlambat.'
            );
        }
    }

    // Daftar pelanggan terlambat (bahasa Indonesia)
    async handleTerlambat(remoteJid) {
        try {
            const overdueInvoices = await billingManager.getOverdueInvoices();
            
            if (overdueInvoices.length === 0) {
                await this.sendFormattedMessage(remoteJid, 
                    '⏰ *PELANGGAN TERLAMBAT BAYAR*\n\n' +
                    'Tidak ada pelanggan yang terlambat bayar.'
                );
                return;
            }

            let message = `⏰ *PELANGGAN TERLAMBAT BAYAR* (${overdueInvoices.length} total)\n\n`;
            
            for (const invoice of overdueInvoices.slice(0, 10)) { // Limit to 10
                const customer = await billingManager.getCustomerById(invoice.customer_id);
                const selectedPackage = await billingManager.getPackageById(invoice.package_id);
                
                const dueDate = new Date(invoice.due_date);
                const today = new Date();
                const daysOverdue = Math.floor((today - dueDate) / (1000 * 60 * 60 * 24));
                
                message += `• *${customer.name}*\n`;
                message += `  📱 ${customer.phone}\n`;
                message += `  📦 ${selectedPackage.name}\n`;
                message += `  💰 Rp${invoice.amount.toLocaleString()}\n`;
                message += `  📅 Jatuh tempo: ${invoice.due_date}\n`;
                message += `  ⏰ ${daysOverdue} hari terlambat\n\n`;
            }

            if (overdueInvoices.length > 10) {
                message += `... dan ${overdueInvoices.length - 10} pelanggan lainnya`;
            }

            await this.sendFormattedMessage(remoteJid, message);
        } catch (error) {
            logger.error('Error in handleTerlambat:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat mengambil daftar pelanggan terlambat.'
            );
        }
    }

    async handleBillingStats(remoteJid) {
        try {
            const stats = await billingManager.getBillingStats();
            const customers = await billingManager.getCustomers();
            const invoices = await billingManager.getInvoices();
            const overdueInvoices = await billingManager.getOverdueInvoices();

            let message = `📈 *STATISTIK BILLING*\n\n`;
            message += `*Total Pelanggan:* ${customers.length}\n`;
            message += `*Total Invoice:* ${invoices.length}\n`;
            message += `*Invoice Paid:* ${invoices.filter(inv => inv.status === 'paid').length}\n`;
            message += `*Invoice Unpaid:* ${invoices.filter(inv => inv.status === 'unpaid').length}\n`;
            message += `*Overdue Invoices:* ${overdueInvoices.length}\n\n`;
            
            message += `*Revenue:*\n`;
            message += `• Total: Rp${stats.totalRevenue?.toLocaleString() || '0'}\n`;
            message += `• This Month: Rp${stats.monthlyRevenue?.toLocaleString() || '0'}\n`;
            message += `• Outstanding: Rp${stats.outstandingAmount?.toLocaleString() || '0'}`;

            await this.sendFormattedMessage(remoteJid, message);
        } catch (error) {
            logger.error('Error in handleBillingStats:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat mengambil statistik billing.'
            );
        }
    }

    // Statistik billing (bahasa Indonesia)
    async handleStatistik(remoteJid) {
        try {
            const stats = await billingManager.getBillingStats();
            const customers = await billingManager.getCustomers();
            const invoices = await billingManager.getInvoices();
            const overdueInvoices = await billingManager.getOverdueInvoices();

            let message = `📈 *STATISTIK BILLING*\n\n`;
            message += `*Total Pelanggan:* ${customers.length}\n`;
            message += `*Total Tagihan:* ${invoices.length}\n`;
            message += `*Sudah Dibayar:* ${invoices.filter(inv => inv.status === 'paid').length}\n`;
            message += `*Belum Dibayar:* ${invoices.filter(inv => inv.status === 'unpaid').length}\n`;
            message += `*Terlambat Bayar:* ${overdueInvoices.length}\n\n`;
            
            message += `*Pendapatan:*\n`;
            message += `• Total: Rp${stats.totalRevenue?.toLocaleString() || '0'}\n`;
            message += `• Bulan Ini: Rp${stats.monthlyRevenue?.toLocaleString() || '0'}\n`;
            message += `• Belum Dibayar: Rp${stats.outstandingAmount?.toLocaleString() || '0'}`;

            await this.sendFormattedMessage(remoteJid, message);
        } catch (error) {
            logger.error('Error in handleStatistik:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat mengambil statistik billing.'
            );
        }
    }

    // Package Management Commands
    async handleAddPackage(remoteJid, params) {
        try {
            if (params.length < 3) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *FORMAT SALAH!*\n\n' +
                    'Format: addpackage [nama] [speed] [harga]\n' +
                    'Contoh: addpackage "Paket Premium" "50 Mbps" 500000'
                );
                return;
            }

            const name = params[0];
            const speed = params[1];
            const price = parseFloat(params[2]);

            const packageData = {
                name: name,
                speed: speed,
                price: price,
                description: `Paket ${name} dengan kecepatan ${speed}`,
                is_active: true
            };

            const result = await billingManager.createPackage(packageData);
            
            if (result.success) {
                await this.sendFormattedMessage(remoteJid, 
                    '✅ *PAKET BERHASIL DITAMBAHKAN!*\n\n' +
                    `*Nama:* ${name}\n` +
                    `*Speed:* ${speed}\n` +
                    `*Harga:* Rp${price.toLocaleString()}\n` +
                    `*Status:* Active`
                );
            } else {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *GAGAL MENAMBAHKAN PAKET!*\n\n' +
                    `Error: ${result.error}`
                );
            }
        } catch (error) {
            logger.error('Error in handleAddPackage:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat menambah paket.'
            );
        }
    }

    async handleListPackages(remoteJid) {
        try {
            const packages = await billingManager.getPackages();
            
            if (packages.length === 0) {
                await this.sendFormattedMessage(remoteJid, 
                    '📦 *DAFTAR PAKET*\n\n' +
                    'Tidak ada paket terdaftar.'
                );
                return;
            }

            let message = `📦 *DAFTAR PAKET* (${packages.length} total)\n\n`;
            
            packages.forEach((pkg, index) => {
                message += `${index + 1}. *${pkg.name}*\n`;
                message += `   🚀 Speed: ${pkg.speed}\n`;
                message += `   💰 Harga: Rp${pkg.price.toLocaleString()}\n`;
                message += `   📊 Status: ${pkg.is_active ? 'Active' : 'Inactive'}\n\n`;
            });

            await this.sendFormattedMessage(remoteJid, message);
        } catch (error) {
            logger.error('Error in handleListPackages:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat mengambil daftar paket.'
            );
        }
    }

    // Daftar paket (bahasa Indonesia)
    async handleDaftarPaket(remoteJid) {
        try {
            const packages = await billingManager.getPackages();
            
            if (packages.length === 0) {
                await this.sendFormattedMessage(remoteJid, 
                    '📦 *DAFTAR PAKET*\n\n' +
                    'Tidak ada paket terdaftar.'
                );
                return;
            }

            let message = `📦 *DAFTAR PAKET* (${packages.length} total)\n\n`;
            
            packages.forEach((pkg, index) => {
                message += `${index + 1}. *${pkg.name}*\n`;
                message += `   🚀 Kecepatan: ${pkg.speed}\n`;
                message += `   💰 Harga: Rp${pkg.price.toLocaleString()}\n`;
                message += `   📊 Status: ${pkg.is_active ? 'Aktif' : 'Tidak Aktif'}\n\n`;
            });

            await this.sendFormattedMessage(remoteJid, message);
        } catch (error) {
            logger.error('Error in handleDaftarPaket:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat mengambil daftar paket.'
            );
        }
    }

    // Invoice Management Commands
    async handleCreateInvoice(remoteJid, params) {
        try {
            if (params.length < 3) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *FORMAT SALAH!*\n\n' +
                    'Format: createinvoice [phone] [amount] [due_date]\n' +
                    'Contoh: createinvoice 081234567890 500000 2024-02-15'
                );
                return;
            }

            const phone = params[0].replace(/\D/g, '');
            const amount = parseFloat(params[1]);
            const dueDate = params[2];

            const customer = await billingManager.getCustomerByPhone(phone);
            if (!customer) {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *PELANGGAN TIDAK DITEMUKAN!*\n\n' +
                    `Phone: ${phone}`
                );
                return;
            }

            const invoiceData = {
                customer_id: customer.id,
                package_id: customer.package_id,
                amount: amount,
                due_date: dueDate,
                status: 'unpaid'
            };

            const result = await billingManager.createInvoice(invoiceData);
            
            if (result.success) {
                await this.sendFormattedMessage(remoteJid, 
                    '✅ *INVOICE BERHASIL DIBUAT!*\n\n' +
                    `*Invoice ID:* ${result.invoice.id}\n` +
                    `*Invoice Number:* ${result.invoice.invoice_number}\n` +
                    `*Pelanggan:* ${customer.name}\n` +
                    `*Phone:* ${customer.phone}\n` +
                    `*Amount:* Rp${amount.toLocaleString()}\n` +
                    `*Due Date:* ${dueDate}\n` +
                    `*Status:* Unpaid`
                );
            } else {
                await this.sendFormattedMessage(remoteJid, 
                    '❌ *GAGAL MEMBUAT INVOICE!*\n\n' +
                    `Error: ${result.error}`
                );
            }
        } catch (error) {
            logger.error('Error in handleCreateInvoice:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat membuat invoice.'
            );
        }
    }

    async handleListInvoices(remoteJid, params) {
        try {
            let invoices;
            let customer = null;

            if (params.length > 0) {
                const phone = params[0].replace(/\D/g, '');
                customer = await billingManager.getCustomerByPhone(phone);
                if (!customer) {
                    await this.sendFormattedMessage(remoteJid, 
                        '❌ *PELANGGAN TIDAK DITEMUKAN!*\n\n' +
                        `Phone: ${phone}`
                    );
                    return;
                }
                invoices = await billingManager.getInvoicesByCustomer(customer.id);
            } else {
                invoices = await billingManager.getInvoices();
            }

            if (invoices.length === 0) {
                const message = customer 
                    ? `📄 *INVOICE PELANGGAN*\n\nTidak ada invoice untuk ${customer.name}`
                    : '📄 *DAFTAR INVOICE*\n\nTidak ada invoice terdaftar.';
                
                await this.sendFormattedMessage(remoteJid, message);
                return;
            }

            let message = customer 
                ? `📄 *INVOICE PELANGGAN: ${customer.name}* (${invoices.length} total)\n\n`
                : `📄 *DAFTAR INVOICE* (${invoices.length} total)\n\n`;

            for (const invoice of invoices.slice(0, 10)) { // Limit to 10
                const invCustomer = customer || await billingManager.getCustomerById(invoice.customer_id);
                const selectedPackage = await billingManager.getPackageById(invoice.package_id);
                
                message += `• *Invoice #${invoice.invoice_number}*\n`;
                message += `  👤 ${invCustomer.name}\n`;
                message += `  📦 ${selectedPackage.name}\n`;
                message += `  💰 Rp${invoice.amount.toLocaleString()}\n`;
                message += `  📅 Due: ${invoice.due_date}\n`;
                message += `  📊 Status: ${invoice.status.toUpperCase()}\n\n`;
            }

            if (invoices.length > 10) {
                message += `... dan ${invoices.length - 10} invoice lainnya`;
            }

            await this.sendFormattedMessage(remoteJid, message);
        } catch (error) {
            logger.error('Error in handleListInvoices:', error);
            await this.sendFormattedMessage(remoteJid, 
                '❌ *ERROR SISTEM!*\n\n' +
                'Terjadi kesalahan saat mengambil daftar invoice.'
            );
        }
    }
}

module.exports = new BillingCommands();
