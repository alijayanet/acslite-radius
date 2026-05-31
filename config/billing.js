const sqlite3 = require('sqlite3').verbose();
const path = require('path');
const fs = require('fs');
const PaymentGatewayManager = require('./paymentGateway');
const logger = require('./logger'); // Added logger import

class BillingManager {
    constructor() {
        this.dbPath = path.join(__dirname, '../data/billing.db');
        this.paymentGateway = new PaymentGatewayManager();
        this.initDatabase();
    }

    // Hot-reload payment gateway configuration from settings.json
    reloadPaymentGateway() {
        try {
            const result = this.paymentGateway.reload();
            return result;
        } catch (e) {
            try { logger.error('[BILLING] Failed to reload payment gateways:', e.message); } catch (_) {}
            return { error: true, message: e.message };
        }
    }

    async setCustomerStatusById(id, status) {
        return new Promise(async (resolve, reject) => {
            try {
                const existing = await this.getCustomerById(id);
                if (!existing) return reject(new Error('Customer not found'));
                const sql = `UPDATE customers SET status = ? WHERE id = ?`;
                this.db.run(sql, [status, id], function(err) {
                    if (err) return reject(err);
                    try {
                        logger.info(`[BILLING] setCustomerStatusById: id=${id}, username=${existing.username}, from=${existing.status} -> to=${status}`);
                    } catch (_) {}
                    resolve({ id, status });
                });
            } catch (e) {
                reject(e);
            }
        });
    }

    initDatabase() {
        // Pastikan direktori data ada
        const dataDir = path.dirname(this.dbPath);
        if (!fs.existsSync(dataDir)) {
            fs.mkdirSync(dataDir, { recursive: true });
        }

        this.db = new sqlite3.Database(this.dbPath, (err) => {
            if (err) {
                console.error('Error opening billing database:', err);
            } else {
                console.log('Billing database connected');
                this.createTables();
            }
        });
    }

    async updateCustomerById(id, customerData) {
        return new Promise(async (resolve, reject) => {
            const { name, username, pppoe_username, email, address, package_id, pppoe_profile, status, auto_suspension, billing_day } = customerData;
            try {
                const oldCustomer = await this.getCustomerById(id);
                if (!oldCustomer) return reject(new Error('Customer not found'));

                const normBillingDay = Math.min(Math.max(parseInt(billing_day !== undefined ? billing_day : (oldCustomer?.billing_day ?? 15), 10) || 15, 1), 28);

                const sql = `UPDATE customers SET name = ?, username = ?, pppoe_username = ?, email = ?, address = ?, package_id = ?, pppoe_profile = ?, status = ?, auto_suspension = ?, billing_day = ? WHERE id = ?`;
                this.db.run(sql, [
                    name ?? oldCustomer.name,
                    username ?? oldCustomer.username,
                    pppoe_username ?? oldCustomer.pppoe_username,
                    email ?? oldCustomer.email,
                    address ?? oldCustomer.address,
                    package_id ?? oldCustomer.package_id,
                    pppoe_profile ?? oldCustomer.pppoe_profile,
                    status ?? oldCustomer.status,
                    auto_suspension !== undefined ? auto_suspension : oldCustomer.auto_suspension,
                    normBillingDay,
                    id
                ], async function(err) {
                    if (err) {
                        reject(err);
                    } else {
                        resolve({ username: oldCustomer.username, id, ...customerData });
                    }
                });
            } catch (error) {
                reject(error);
            }
        });
    }

    createTables() {
        const tables = [
            // Tabel paket internet
            `CREATE TABLE IF NOT EXISTS packages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                speed TEXT NOT NULL,
                price DECIMAL(10,2) NOT NULL,
                description TEXT,
                pppoe_profile TEXT DEFAULT 'default',
                is_active BOOLEAN DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )`,

            // Tabel pelanggan
            `CREATE TABLE IF NOT EXISTS customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                name TEXT NOT NULL,
                phone TEXT UNIQUE NOT NULL,
                pppoe_username TEXT,
                email TEXT,
                address TEXT,
                package_id INTEGER,
                pppoe_profile TEXT,
                status TEXT DEFAULT 'active',
                join_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (package_id) REFERENCES packages (id)
            )`,

            // Tabel tagihan
            `CREATE TABLE IF NOT EXISTS invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id INTEGER NOT NULL,
                package_id INTEGER NOT NULL,
                invoice_number TEXT UNIQUE NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                due_date DATE NOT NULL,
                status TEXT DEFAULT 'unpaid',
                payment_date DATETIME,
                payment_method TEXT,
                payment_gateway TEXT,
                payment_token TEXT,
                payment_url TEXT,
                payment_status TEXT DEFAULT 'pending',
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (customer_id) REFERENCES customers (id),
                FOREIGN KEY (package_id) REFERENCES packages (id)
            )`,

            // Tabel pembayaran
            `CREATE TABLE IF NOT EXISTS payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_id INTEGER NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                payment_method TEXT NOT NULL,
                reference_number TEXT,
                notes TEXT,
                FOREIGN KEY (invoice_id) REFERENCES invoices (id)
            )`,

            // Tabel transaksi payment gateway
            `CREATE TABLE IF NOT EXISTS payment_gateway_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_id INTEGER NOT NULL,
                gateway TEXT NOT NULL,
                order_id TEXT NOT NULL,
                payment_url TEXT,
                token TEXT,
                amount DECIMAL(10,2) NOT NULL,
                status TEXT DEFAULT 'pending',
                payment_type TEXT,
                fraud_status TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (invoice_id) REFERENCES invoices (id)
            )`
        ];

        tables.forEach(table => {
            this.db.run(table, (err) => {
                if (err) {
                    console.error('Error creating table:', err);
                }
            });
        });

        // Tambahkan kolom pppoe_username jika belum ada
        this.db.run("ALTER TABLE customers ADD COLUMN pppoe_username TEXT", (err) => {
            if (err && !err.message.includes('duplicate column name')) {
                console.error('Error adding pppoe_username column:', err);
            }
        });

        // Tambahkan kolom payment_gateway jika belum ada
        this.db.run("ALTER TABLE invoices ADD COLUMN payment_gateway TEXT", (err) => {
            if (err && !err.message.includes('duplicate column name')) {
                console.error('Error adding payment_gateway column:', err);
            }
        });

        // Tambahkan kolom payment_token jika belum ada
        this.db.run("ALTER TABLE invoices ADD COLUMN payment_token TEXT", (err) => {
            if (err && !err.message.includes('duplicate column name')) {
                console.error('Error adding payment_token column:', err);
            }
        });

        // Tambahkan kolom payment_url jika belum ada
        this.db.run("ALTER TABLE invoices ADD COLUMN payment_url TEXT", (err) => {
            if (err && !err.message.includes('duplicate column name')) {
                console.error('Error adding payment_url column:', err);
            }
        });

        // Tambahkan kolom payment_status jika belum ada
        this.db.run("ALTER TABLE invoices ADD COLUMN payment_status TEXT DEFAULT 'pending'", (err) => {
            if (err && !err.message.includes('duplicate column name')) {
                console.error('Error adding payment_status column:', err);
            }
        });

        // Tambahkan kolom pppoe_profile ke packages jika belum ada
        this.db.run("ALTER TABLE packages ADD COLUMN pppoe_profile TEXT DEFAULT 'default'", (err) => {
            if (err && !err.message.includes('duplicate column name')) {
                console.error('Error adding pppoe_profile column to packages:', err);
            } else if (!err) {
                console.log('Added pppoe_profile column to packages table');
            }
        });

        // Tambahkan kolom pppoe_profile ke customers jika belum ada
        this.db.run("ALTER TABLE customers ADD COLUMN pppoe_profile TEXT", (err) => {
            if (err && !err.message.includes('duplicate column name')) {
                console.error('Error adding pppoe_profile column to customers:', err);
            } else if (!err) {
                console.log('Added pppoe_profile column to customers table');
            }
        });

        // Tambahkan kolom auto_suspension ke customers jika belum ada
        this.db.run("ALTER TABLE customers ADD COLUMN auto_suspension BOOLEAN DEFAULT 1", (err) => {
            if (err && !err.message.includes('duplicate column name')) {
                console.error('Error adding auto_suspension column:', err);
            } else if (!err) {
                console.log('Added auto_suspension column to customers table');
            }
        });

        // Tambahkan kolom billing_day ke customers jika belum ada
        this.db.run("ALTER TABLE customers ADD COLUMN billing_day INTEGER DEFAULT 15", (err) => {
            if (err && !err.message.includes('duplicate column name')) {
                console.error('Error adding billing_day column:', err);
            } else if (!err) {
                console.log('Added billing_day column to customers table');
            }
        });

        // Update existing customers to have username if null (for backward compatibility)
        this.db.run("UPDATE customers SET username = 'cust_' || substr(phone, -4, 4) || '_' || strftime('%s','now') WHERE username IS NULL OR username = ''", (err) => {
            if (err) {
                console.error('Error updating null usernames:', err);
            } else {
                console.log('Updated null usernames for existing customers');
            }
        });
    }

    // Paket Management
    async createPackage(packageData) {
        return new Promise((resolve, reject) => {
            const { name, speed, price, description, pppoe_profile } = packageData;
            const sql = `INSERT INTO packages (name, speed, price, description, pppoe_profile) VALUES (?, ?, ?, ?, ?)`;
            
            this.db.run(sql, [name, speed, price, description, pppoe_profile || 'default'], function(err) {
                if (err) {
                    reject(err);
                } else {
                    resolve({ id: this.lastID, ...packageData });
                }
            });
        });
    }

    async getPackages() {
        return new Promise((resolve, reject) => {
            const sql = `SELECT * FROM packages WHERE is_active = 1 ORDER BY price ASC`;
            
            this.db.all(sql, [], (err, rows) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(rows);
                }
            });
        });
    }

    async getPackageById(id) {
        return new Promise((resolve, reject) => {
            const sql = `SELECT * FROM packages WHERE id = ?`;
            
            this.db.get(sql, [id], (err, row) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(row);
                }
            });
        });
    }

    async updatePackage(id, packageData) {
        return new Promise((resolve, reject) => {
            const { name, speed, price, description, pppoe_profile } = packageData;
            const sql = `UPDATE packages SET name = ?, speed = ?, price = ?, description = ?, pppoe_profile = ? WHERE id = ?`;
            
            this.db.run(sql, [name, speed, price, description, pppoe_profile || 'default', id], function(err) {
                if (err) {
                    reject(err);
                } else {
                    resolve({ id, ...packageData });
                }
            });
        });
    }

    async deletePackage(id) {
        return new Promise((resolve, reject) => {
            const sql = `UPDATE packages SET is_active = 0 WHERE id = ?`;
            
            this.db.run(sql, [id], function(err) {
                if (err) {
                    reject(err);
                } else {
                    resolve({ id, deleted: true });
                }
            });
        });
    }

    // Customer Management
    async createCustomer(customerData) {
        return new Promise(async (resolve, reject) => {
            const { name, username, phone, pppoe_username, email, address, package_id, pppoe_profile, status, auto_suspension, billing_day } = customerData;
            
            // Use provided username, fallback to auto-generate if not provided
            const finalUsername = username || this.generateUsername(phone);
            const autoPPPoEUsername = pppoe_username || this.generatePPPoEUsername(phone);
            
            // Normalisasi billing_day (1-28)
            const normBillingDay = Math.min(Math.max(parseInt(billing_day ?? 15, 10) || 15, 1), 28);
            
            const sql = `INSERT INTO customers (username, name, phone, pppoe_username, email, address, package_id, pppoe_profile, status, auto_suspension, billing_day) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`;
            
            this.db.run(sql, [finalUsername, name, phone, autoPPPoEUsername, email, address, package_id, pppoe_profile, status || 'active', auto_suspension !== undefined ? auto_suspension : 1, normBillingDay], async function(err) {
                if (err) {
                    reject(err);
                } else {
                    const customer = { id: this.lastID, ...customerData };
                    
                    // Jika ada nomor telepon dan PPPoE username, coba tambahkan tag ke GenieACS
                    if (phone && autoPPPoEUsername) {
                        try {
                            const { findDeviceByPPPoE, addTagToDevice } = require('./genieacs');
                            // Cari device berdasarkan PPPoE Username
                            const device = await findDeviceByPPPoE(autoPPPoEUsername);
                            
                            if (device) {
                                // Tambahkan tag nomor telepon ke device
                                await addTagToDevice(device._id, phone);
                                console.log(`Successfully added phone tag ${phone} to device ${device._id} for customer ${finalUsername} (PPPoE: ${autoPPPoEUsername})`);
                            } else {
                                console.warn(`No device found with PPPoE Username ${autoPPPoEUsername} for customer ${finalUsername}`);
                            }
                        } catch (genieacsError) {
                            console.error(`Error adding phone tag to GenieACS for customer ${finalUsername}:`, genieacsError.message);
                            // Jangan reject, karena customer sudah berhasil dibuat di billing
                        }
                    } else if (phone && finalUsername) {
                        // Fallback: coba dengan username jika pppoe_username tidak ada
                        try {
                            const { findDeviceByPPPoE, addTagToDevice } = require('./genieacs');
                            const device = await findDeviceByPPPoE(finalUsername);
                            
                            if (device) {
                                await addTagToDevice(device._id, phone);
                                console.log(`Successfully added phone tag ${phone} to device ${device._id} for customer ${finalUsername} (using username as PPPoE)`);
                            } else {
                                console.warn(`No device found with PPPoE Username ${finalUsername} for customer ${finalUsername}`);
                            }
                        } catch (genieacsError) {
                            console.error(`Error adding phone tag to GenieACS for customer ${finalUsername}:`, genieacsError.message);
                        }
                    }
                    
                    resolve(customer);
                }
            });
        });
    }

    async getCustomers() {
        return new Promise((resolve, reject) => {
            const sql = `
                SELECT c.*, p.name as package_name, p.price as package_price,
                       CASE 
                           WHEN EXISTS (
                               SELECT 1 FROM invoices i 
                               WHERE i.customer_id = c.id 
                               AND i.status = 'unpaid' 
                               AND i.due_date < date('now')
                           ) THEN 'overdue'
                           WHEN EXISTS (
                               SELECT 1 FROM invoices i 
                               WHERE i.customer_id = c.id 
                               AND i.status = 'unpaid'
                           ) THEN 'unpaid'
                           WHEN EXISTS (
                               SELECT 1 FROM invoices i 
                               WHERE i.customer_id = c.id 
                               AND i.status = 'paid'
                           ) THEN 'paid'
                           ELSE 'no_invoice'
                       END as payment_status
                FROM customers c 
                LEFT JOIN packages p ON c.package_id = p.id 
                ORDER BY c.name ASC
            `;
            
            this.db.all(sql, [], (err, rows) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(rows);
                }
            });
        });
    }

    async getCustomerByUsername(username) {
        return new Promise((resolve, reject) => {
            const sql = `
                SELECT c.*, p.name as package_name, p.price as package_price, p.speed as package_speed
                FROM customers c 
                LEFT JOIN packages p ON c.package_id = p.id 
                WHERE c.username = ?
            `;
            
            this.db.get(sql, [username], (err, row) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(row);
                }
            });
        });
    }

    async getCustomerById(id) {
        return new Promise((resolve, reject) => {
            const sql = `
                SELECT c.*, p.name as package_name, p.price as package_price, p.speed as package_speed
                FROM customers c 
                LEFT JOIN packages p ON c.package_id = p.id 
                WHERE c.id = ?
            `;
            
            this.db.get(sql, [id], (err, row) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(row);
                }
            });
        });
    }

    async getCustomerByPhone(phone) {
        return new Promise((resolve, reject) => {
            const sql = `
                SELECT c.*, p.name as package_name, p.price as package_price, p.speed as package_speed,
                       CASE 
                           WHEN EXISTS (
                               SELECT 1 FROM invoices i 
                               WHERE i.customer_id = c.id 
                               AND i.status = 'unpaid' 
                               AND i.due_date < date('now')
                           ) THEN 'overdue'
                           WHEN EXISTS (
                               SELECT 1 FROM invoices i 
                               WHERE i.customer_id = c.id 
                               AND i.status = 'unpaid'
                           ) THEN 'unpaid'
                           WHEN EXISTS (
                               SELECT 1 FROM invoices i 
                               WHERE i.customer_id = c.id 
                               AND i.status = 'paid'
                           ) THEN 'paid'
                           ELSE 'no_invoice'
                       END as payment_status
                FROM customers c 
                LEFT JOIN packages p ON c.package_id = p.id 
                WHERE c.phone = ?
            `;
            
            this.db.get(sql, [phone], (err, row) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(row);
                }
            });
        });
    }

    async getCustomerByNameOrPhone(searchTerm) {
        return new Promise((resolve, reject) => {
            // Bersihkan nomor telefon (hapus karakter non-digit)
            const cleanPhone = searchTerm.replace(/\D/g, '');
            
            const sql = `
                SELECT c.*, p.name as package_name, p.price as package_price, p.speed as package_speed,
                       CASE 
                           WHEN EXISTS (
                               SELECT 1 FROM invoices i 
                               WHERE i.customer_id = c.id 
                               AND i.status = 'unpaid' 
                               AND i.due_date < date('now')
                           ) THEN 'overdue'
                           WHEN EXISTS (
                               SELECT 1 FROM invoices i 
                               WHERE i.customer_id = c.id 
                               AND i.status = 'unpaid'
                           ) THEN 'unpaid'
                           WHEN EXISTS (
                               SELECT 1 FROM invoices i 
                               WHERE i.customer_id = c.id 
                               AND i.status = 'paid'
                           ) THEN 'paid'
                           ELSE 'no_invoice'
                       END as payment_status
                FROM customers c 
                LEFT JOIN packages p ON c.package_id = p.id 
                WHERE c.phone = ? 
                   OR c.name LIKE ? 
                   OR c.username LIKE ?
                ORDER BY 
                    CASE 
                        WHEN c.phone = ? THEN 1
                        WHEN c.name = ? THEN 2
                        WHEN c.name LIKE ? THEN 3
                        WHEN c.username LIKE ? THEN 4
                        ELSE 5
                    END
                LIMIT 1
            `;
            
            const likeTerm = `%${searchTerm}%`;
            const params = [
                cleanPhone,           // Exact phone match
                likeTerm,            // Name LIKE
                likeTerm,            // Username LIKE
                cleanPhone,          // ORDER BY phone exact
                searchTerm,          // ORDER BY name exact
                `${searchTerm}%`,    // ORDER BY name starts with
                likeTerm             // ORDER BY username LIKE
            ];
            
            this.db.get(sql, params, (err, row) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(row);
                }
            });
        });
    }

    async findCustomersByNameOrPhone(searchTerm) {
        return new Promise((resolve, reject) => {
            // Bersihkan nomor telefon (hapus karakter non-digit) 
            const cleanPhone = searchTerm.replace(/\D/g, '');
            
            const sql = `
                SELECT c.*, p.name as package_name, p.price as package_price, p.speed as package_speed,
                       CASE 
                           WHEN EXISTS (
                               SELECT 1 FROM invoices i 
                               WHERE i.customer_id = c.id 
                               AND i.status = 'unpaid' 
                               AND i.due_date < date('now')
                           ) THEN 'overdue'
                           WHEN EXISTS (
                               SELECT 1 FROM invoices i 
                               WHERE i.customer_id = c.id 
                               AND i.status = 'unpaid'
                           ) THEN 'unpaid'
                           WHEN EXISTS (
                               SELECT 1 FROM invoices i 
                               WHERE i.customer_id = c.id 
                               AND i.status = 'paid'
                           ) THEN 'paid'
                           ELSE 'no_invoice'
                       END as payment_status
                FROM customers c 
                LEFT JOIN packages p ON c.package_id = p.id 
                WHERE c.phone = ? 
                   OR c.name LIKE ? 
                   OR c.username LIKE ?
                ORDER BY 
                    CASE 
                        WHEN c.phone = ? THEN 1
                        WHEN c.name = ? THEN 2
                        WHEN c.name LIKE ? THEN 3
                        WHEN c.username LIKE ? THEN 4
                        ELSE 5
                    END
                LIMIT 5
            `;
            
            const likeTerm = `%${searchTerm}%`;
            const params = [
                cleanPhone,           // Exact phone match
                likeTerm,            // Name LIKE
                likeTerm,            // Username LIKE
                cleanPhone,          // ORDER BY phone exact
                searchTerm,          // ORDER BY name exact
                `${searchTerm}%`,    // ORDER BY name starts with
                likeTerm             // ORDER BY username LIKE
            ];
            
            this.db.all(sql, params, (err, rows) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(rows || []);
                }
            });
        });
    }

    async updateCustomer(phone, customerData) {
        return this.updateCustomerByPhone(phone, customerData);
    }

    async updateCustomerByPhone(oldPhone, customerData) {
        return new Promise(async (resolve, reject) => {
            const { name, username, phone, pppoe_username, email, address, package_id, pppoe_profile, status, auto_suspension, billing_day } = customerData;
            
            // Dapatkan data customer lama untuk membandingkan nomor telepon
            try {
                const oldCustomer = await this.getCustomerByPhone(oldPhone);
                if (!oldCustomer) {
                    return reject(new Error('Pelanggan tidak ditemukan'));
                }
                
                const oldPPPoE = oldCustomer ? oldCustomer.pppoe_username : null;
                
                // Normalisasi billing_day (1-28) dengan fallback ke nilai lama atau 15
                const normBillingDay = Math.min(Math.max(parseInt(billing_day !== undefined ? billing_day : (oldCustomer?.billing_day ?? 15), 10) || 15, 1), 28);
                
                const sql = `UPDATE customers SET name = ?, username = ?, phone = ?, pppoe_username = ?, email = ?, address = ?, package_id = ?, pppoe_profile = ?, status = ?, auto_suspension = ?, billing_day = ? WHERE id = ?`;
                
                this.db.run(sql, [name, username || oldCustomer.username, phone || oldPhone, pppoe_username, email, address, package_id, pppoe_profile, status, auto_suspension !== undefined ? auto_suspension : oldCustomer.auto_suspension, normBillingDay, oldCustomer.id], async function(err) {
                    if (err) {
                        reject(err);
                    } else {
                        // Jika nomor telepon atau PPPoE username berubah, update tag di GenieACS
                        const newPhone = phone || oldPhone;
                        if (newPhone && (newPhone !== oldPhone || pppoe_username !== oldPPPoE)) {
                            try {
                                const { findDeviceByPPPoE, addTagToDevice, removeTagFromDevice } = require('./genieacs');
                                
                                // Hapus tag lama jika ada
                                                                        if (oldPhone && oldPPPoE) {
                                    try {
                                        const oldDevice = await findDeviceByPPPoE(oldPPPoE);
                                        if (oldDevice) {
                                            await removeTagFromDevice(oldDevice._id, oldPhone);
                                            console.log(`Removed old phone tag ${oldPhone} from device ${oldDevice._id} for customer ${oldCustomer.username}`);
                                        }
                                    } catch (error) {
                                        console.warn(`Error removing old phone tag for customer ${oldCustomer.username}:`, error.message);
                                    }
                                }
                                
                                // Tambahkan tag baru
                                const pppoeToUse = pppoe_username || oldCustomer.username; // Fallback ke username jika pppoe_username kosong
                                const device = await findDeviceByPPPoE(pppoeToUse);
                                
                                if (device) {
                                    await addTagToDevice(device._id, newPhone);
                                    console.log(`Successfully updated phone tag to ${newPhone} for device ${device._id} and customer ${oldCustomer.username} (PPPoE: ${pppoeToUse})`);
                                } else {
                                    console.warn(`No device found with PPPoE Username ${pppoeToUse} for customer ${oldCustomer.username}`);
                                }
                            } catch (genieacsError) {
                                console.error(`Error updating phone tag in GenieACS for customer ${oldCustomer.username}:`, genieacsError.message);
                                // Jangan reject, karena customer sudah berhasil diupdate di billing
                            }
                        }
                        
                        resolve({ username: oldCustomer.username, ...customerData });
                    }
                });
            } catch (error) {
                reject(error);
            }
        });
    }

    async deleteCustomer(phone) {
        return new Promise(async (resolve, reject) => {
            try {
                // Dapatkan data customer sebelum dihapus
                const customer = await this.getCustomerByPhone(phone);
                if (!customer) {
                    reject(new Error('Pelanggan tidak ditemukan'));
                    return;
                }

                // Cek apakah ada invoice yang terkait dengan customer ini
                const invoices = await this.getInvoicesByCustomer(customer.id);
                if (invoices && invoices.length > 0) {
                    reject(new Error(`Tidak dapat menghapus pelanggan: ${invoices.length} tagihan masih ada untuk pelanggan ini. Silakan hapus semua tagihan terlebih dahulu.`));
                    return;
                }

                const sql = `DELETE FROM customers WHERE phone = ?`;
                
                this.db.run(sql, [phone], async function(err) {
                    if (err) {
                        reject(err);
                    } else {
                        // Hapus tag dari GenieACS jika ada nomor telepon
                        if (customer.phone) {
                            try {
                                const { findDeviceByPPPoE, removeTagFromDevice } = require('./genieacs');
                                const pppoeToUse = customer.pppoe_username || customer.username; // Fallback ke username jika pppoe_username kosong
                                const device = await findDeviceByPPPoE(pppoeToUse);
                                
                                if (device) {
                                    await removeTagFromDevice(device._id, customer.phone);
                                    console.log(`Removed phone tag ${customer.phone} from device ${device._id} for deleted customer ${customer.username} (PPPoE: ${pppoeToUse})`);
                                } else {
                                    console.warn(`No device found with PPPoE Username ${pppoeToUse} for deleted customer ${customer.username}`);
                                }
                            } catch (genieacsError) {
                                console.error(`Error removing phone tag from GenieACS for deleted customer ${customer.username}:`, genieacsError.message);
                                // Jangan reject, karena customer sudah berhasil dihapus di billing
                                // Log error tapi lanjutkan proses
                            }
                        }
                        
                        resolve({ username: customer.username, deleted: true });
                    }
                });
            } catch (error) {
                reject(error);
            }
        });
    }

    // Invoice Management
    async createInvoice(invoiceData) {
        return new Promise((resolve, reject) => {
            const { customer_id, package_id, amount, due_date, notes } = invoiceData;
            const invoice_number = this.generateInvoiceNumber();
            
            const sql = `INSERT INTO invoices (customer_id, package_id, invoice_number, amount, due_date, notes) VALUES (?, ?, ?, ?, ?, ?)`;
            
            this.db.run(sql, [customer_id, package_id, invoice_number, amount, due_date, notes], function(err) {
                if (err) {
                    reject(err);
                } else {
                    resolve({ id: this.lastID, invoice_number, ...invoiceData });
                }
            });
        });
    }

    async getInvoices(customerUsername = null) {
        return new Promise((resolve, reject) => {
            let sql = `
                SELECT i.*, c.username, c.name as customer_name, c.phone as customer_phone,
                       p.name as package_name, p.speed as package_speed
                FROM invoices i
                JOIN customers c ON i.customer_id = c.id
                JOIN packages p ON i.package_id = p.id
            `;
            
            const params = [];
            if (customerUsername) {
                sql += ` WHERE c.username = ?`;
                params.push(customerUsername);
            }
            
            sql += ` ORDER BY i.created_at DESC`;
            
            this.db.all(sql, params, (err, rows) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(rows);
                }
            });
        });
    }

    async getInvoicesByCustomer(customerId) {
        return new Promise((resolve, reject) => {
            const sql = `
                SELECT i.*, c.username, c.name as customer_name, c.phone as customer_phone,
                       p.name as package_name, p.speed as package_speed
                FROM invoices i
                JOIN customers c ON i.customer_id = c.id
                JOIN packages p ON i.package_id = p.id
                WHERE i.customer_id = ?
                ORDER BY i.created_at DESC
            `;
            
            this.db.all(sql, [customerId], (err, rows) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(rows);
                }
            });
        });
    }

    async getCustomersByPackage(packageId) {
        return new Promise((resolve, reject) => {
            const sql = `
                SELECT c.*, p.name as package_name, p.price as package_price, p.speed as package_speed
                FROM customers c
                LEFT JOIN packages p ON c.package_id = p.id
                WHERE c.package_id = ?
                ORDER BY c.name ASC
            `;
            
            this.db.all(sql, [packageId], (err, rows) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(rows);
                }
            });
        });
    }

    async getInvoicesByCustomerAndDateRange(customerUsername, startDate, endDate) {
        return new Promise((resolve, reject) => {
            const sql = `
                SELECT i.*, c.username, c.name as customer_name, c.phone as customer_phone,
                       p.name as package_name, p.speed as package_speed
                FROM invoices i
                JOIN customers c ON i.customer_id = c.id
                JOIN packages p ON i.package_id = p.id
                WHERE c.username = ? 
                AND i.created_at BETWEEN ? AND ?
                ORDER BY i.created_at DESC
            `;
            
            const params = [
                customerUsername,
                startDate.toISOString(),
                endDate.toISOString()
            ];
            
            this.db.all(sql, params, (err, rows) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(rows);
                }
            });
        });
    }

    async getInvoiceById(id) {
        return new Promise((resolve, reject) => {
            const sql = `
                SELECT i.*, c.username as customer_username, c.name as customer_name, c.phone as customer_phone, c.address as customer_address,
                       p.name as package_name, p.speed as package_speed
                FROM invoices i
                JOIN customers c ON i.customer_id = c.id
                JOIN packages p ON i.package_id = p.id
                WHERE i.id = ?
            `;
            
            this.db.get(sql, [id], (err, row) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(row);
                }
            });
        });
    }

    async updateInvoiceStatus(id, status, paymentMethod = null) {
        return new Promise((resolve, reject) => {
            const paymentDate = status === 'paid' ? new Date().toISOString() : null;
            const sql = `UPDATE invoices SET status = ?, payment_date = ?, payment_method = ? WHERE id = ?`;
            
            this.db.run(sql, [status, paymentDate, paymentMethod, id], function(err) {
                if (err) {
                    reject(err);
                } else {
                    resolve({ id, status, payment_date: paymentDate, payment_method: paymentMethod });
                }
            });
        });
    }

    async updateInvoice(id, invoiceData) {
        return new Promise((resolve, reject) => {
            const { customer_id, package_id, amount, due_date, notes } = invoiceData;
            const sql = `UPDATE invoices SET customer_id = ?, package_id = ?, amount = ?, due_date = ?, notes = ? WHERE id = ?`;
            
            // Use arrow function to preserve class context (this)
            this.db.run(sql, [customer_id, package_id, amount, due_date, notes, id], (err) => {
                if (err) {
                    reject(err);
                } else {
                    // Get the updated invoice
                    this.getInvoiceById(id).then(resolve).catch(reject);
                }
            });
        });
    }

    async deleteInvoice(id) {
        return new Promise((resolve, reject) => {
            // First get the invoice details before deleting
            this.getInvoiceById(id).then(invoice => {
                const sql = `DELETE FROM invoices WHERE id = ?`;
                this.db.run(sql, [id], function(err) {
                    if (err) {
                        reject(err);
                    } else {
                        resolve(invoice);
                    }
                });
            }).catch(reject);
        });
    }

    // Payment Management
    async recordPayment(paymentData) {
        return new Promise((resolve, reject) => {
            const { invoice_id, amount, payment_method, reference_number, notes } = paymentData;
            const sql = `INSERT INTO payments (invoice_id, amount, payment_method, reference_number, notes) VALUES (?, ?, ?, ?, ?)`;
            
            this.db.run(sql, [invoice_id, amount, payment_method, reference_number, notes], function(err) {
                if (err) {
                    reject(err);
                } else {
                    resolve({ 
                        success: true, 
                        id: this.lastID, 
                        ...paymentData 
                    });
                }
            });
        });
    }

    async getPayments(invoiceId = null) {
        return new Promise((resolve, reject) => {
            let sql = `
                SELECT p.*, i.invoice_number, c.username, c.name as customer_name
                FROM payments p
                JOIN invoices i ON p.invoice_id = i.id
                JOIN customers c ON i.customer_id = c.id
            `;
            
            const params = [];
            if (invoiceId) {
                sql += ` WHERE p.invoice_id = ?`;
                params.push(invoiceId);
            }
            
            sql += ` ORDER BY p.payment_date DESC`;
            
            this.db.all(sql, params, (err, rows) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(rows);
                }
            });
        });
    }

    async getPaymentById(id) {
        return new Promise((resolve, reject) => {
            const sql = `
                SELECT p.*, i.invoice_number, c.username, c.name as customer_name
                FROM payments p
                JOIN invoices i ON p.invoice_id = i.id
                JOIN customers c ON i.customer_id = c.id
                WHERE p.id = ?
            `;
            
            this.db.get(sql, [id], (err, row) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(row);
                }
            });
        });
    }

    async updatePayment(id, paymentData) {
        return new Promise((resolve, reject) => {
            const { amount, payment_method, reference_number, notes } = paymentData;
            const sql = `UPDATE payments SET amount = ?, payment_method = ?, reference_number = ?, notes = ? WHERE id = ?`;
            this.db.run(sql, [amount, payment_method, reference_number, notes, id], (err) => {
                if (err) {
                    reject(err);
                } else {
                    this.getPaymentById(id).then(resolve).catch(reject);
                }
            });
        });
    }

    async deletePayment(id) {
        return new Promise((resolve, reject) => {
            // Ambil payment terlebih dahulu untuk reference
            this.getPaymentById(id).then(payment => {
                if (!payment) return reject(new Error('Payment not found'));
                const sql = `DELETE FROM payments WHERE id = ?`;
                this.db.run(sql, [id], (err) => {
                    if (err) {
                        reject(err);
                    } else {
                        resolve(payment);
                    }
                });
            }).catch(reject);
        });
    }

    // Utility functions
    generateInvoiceNumber() {
        const date = new Date();
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const random = Math.floor(Math.random() * 10000).toString().padStart(4, '0');
        return `INV-${year}${month}-${random}`;
    }

    // Generate username otomatis berdasarkan nomor telepon
    generateUsername(phone) {
        // Ambil 4 digit terakhir dari nomor telepon
        const last4Digits = phone.slice(-4);
        const timestamp = Date.now().toString().slice(-6);
        // Tambah random string untuk menghindari collision
        const randomStr = Math.random().toString(36).substring(2, 6);
        return `cust_${last4Digits}_${timestamp}_${randomStr}`;
    }

    // Generate PPPoE username otomatis
    generatePPPoEUsername(phone) {
        // Ambil 4 digit terakhir dari nomor telepon
        const last4Digits = phone.slice(-4);
        // Tambah random string untuk menghindari collision
        const randomStr = Math.random().toString(36).substring(2, 4);
        return `pppoe_${last4Digits}_${randomStr}`;
    }

    async getBillingStats() {
        return new Promise((resolve, reject) => {
            const sql = `
                SELECT 
                    COUNT(DISTINCT c.id) as total_customers,
                    COUNT(CASE WHEN c.status = 'active' THEN 1 END) as active_customers,
                    COUNT(i.id) as total_invoices,
                    COUNT(CASE WHEN i.status = 'paid' THEN 1 END) as paid_invoices,
                    COUNT(CASE WHEN i.status = 'unpaid' THEN 1 END) as unpaid_invoices,
                    SUM(CASE WHEN i.status = 'paid' THEN i.amount ELSE 0 END) as total_revenue,
                    SUM(CASE WHEN i.status = 'unpaid' THEN i.amount ELSE 0 END) as total_unpaid
                FROM customers c
                LEFT JOIN invoices i ON c.id = i.customer_id
            `;
            
            this.db.get(sql, [], (err, row) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(row);
                }
            });
        });
    }

    async getOverdueInvoices() {
        return new Promise((resolve, reject) => {
            const sql = `
                SELECT i.*, c.username, c.name as customer_name, c.phone as customer_phone,
                       p.name as package_name
                FROM invoices i
                JOIN customers c ON i.customer_id = c.id
                JOIN packages p ON i.package_id = p.id
                WHERE i.status = 'unpaid' AND i.due_date < date('now')
                ORDER BY i.due_date ASC
            `;
            
            this.db.all(sql, [], (err, rows) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(rows);
                }
            });
        });
    }

    // Close database connection
    close() {
        if (this.db) {
            this.db.close((err) => {
                if (err) {
                    console.error('Error closing billing database:', err);
                } else {
                    console.log('Billing database connection closed');
                }
            });
        }
    }

    // Payment Gateway Methods
    async createOnlinePayment(invoiceId, gateway = null) {
        return new Promise(async (resolve, reject) => {
            try {
                // Get invoice details
                const invoice = await this.getInvoiceById(invoiceId);
                if (!invoice) {
                    throw new Error('Invoice not found');
                }

                // Get customer details
                const customer = await this.getCustomerById(invoice.customer_id);
                if (!customer) {
                    throw new Error('Customer not found');
                }

                // Prepare invoice data for payment gateway
                const paymentData = {
                    id: invoice.id,
                    invoice_number: invoice.invoice_number,
                    amount: invoice.amount,
                    customer_name: customer.name,
                    customer_phone: customer.phone,
                    customer_email: customer.email,
                    package_name: invoice.package_name,
                    package_id: invoice.package_id
                };

                // Create payment with selected gateway
                const paymentResult = await this.paymentGateway.createPayment(paymentData, gateway);

                // Save payment transaction to database
                const sql = `
                    INSERT INTO payment_gateway_transactions 
                    (invoice_id, gateway, order_id, payment_url, token, amount, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                `;

                const db = this.db;
                db.run(sql, [
                    invoiceId,
                    paymentResult.gateway,
                    paymentResult.order_id,
                    paymentResult.payment_url,
                    paymentResult.token,
                    invoice.amount,
                    'pending'
                ], (err) => {
                    if (err) {
                        reject(err);
                    } else {
                        // Update invoice with payment gateway info
                        const updateSql = `
                            UPDATE invoices 
                            SET payment_gateway = ?, payment_token = ?, payment_url = ?, payment_status = 'pending'
                            WHERE id = ?
                        `;

                        db.run(updateSql, [
                            paymentResult.gateway,
                            paymentResult.token,
                            paymentResult.payment_url,
                            invoiceId
                        ], (updateErr) => {
                            if (updateErr) {
                                reject(updateErr);
                            } else {
                                resolve(paymentResult);
                            }
                        });
                    }
                });
            } catch (error) {
                reject(error);
            }
        });
    }

    // Create online payment with specific method (for customer choice)
    async createOnlinePaymentWithMethod(invoiceId, gateway = null, method = null) {
        return new Promise(async (resolve, reject) => {
            try {
                // Get invoice details
                const invoice = await this.getInvoiceById(invoiceId);
                if (!invoice) {
                    throw new Error('Invoice not found');
                }

                // Get customer details
                const customer = await this.getCustomerById(invoice.customer_id);
                if (!customer) {
                    throw new Error('Customer not found');
                }

                // Prepare invoice data for payment gateway
                const paymentData = {
                    id: invoice.id,
                    invoice_number: invoice.invoice_number,
                    amount: invoice.amount,
                    customer_name: customer.name,
                    customer_phone: customer.phone,
                    customer_email: customer.email,
                    package_name: invoice.package_name,
                    package_id: invoice.package_id,
                    payment_method: method // Add specific method for Tripay
                };

                // Create payment with selected gateway and method
                const paymentResult = await this.paymentGateway.createPaymentWithMethod(paymentData, gateway, method);

                // Save payment transaction to database
                const sql = `
                    INSERT INTO payment_gateway_transactions 
                    (invoice_id, gateway, order_id, payment_url, token, amount, status, payment_type) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                `;

                const db = this.db;
                db.run(sql, [
                    invoiceId,
                    paymentResult.gateway,
                    paymentResult.order_id,
                    paymentResult.payment_url,
                    paymentResult.token,
                    invoice.amount,
                    'pending',
                    method || 'all'
                ], (err) => {
                    if (err) {
                        reject(err);
                    } else {
                        // Update invoice with payment gateway info
                        const updateSql = `
                            UPDATE invoices 
                            SET payment_gateway = ?, payment_token = ?, payment_url = ?, payment_status = 'pending'
                            WHERE id = ?
                        `;

                        db.run(updateSql, [
                            paymentResult.gateway,
                            paymentResult.token,
                            paymentResult.payment_url,
                            invoiceId
                        ], (updateErr) => {
                            if (updateErr) {
                                reject(updateErr);
                            } else {
                                resolve(paymentResult);
                            }
                        });
                    }
                });
            } catch (error) {
                reject(error);
            }
        });
    }

async handlePaymentWebhook(payload, gateway) {
    return new Promise(async (resolve, reject) => {
        try {
            logger.info(`[WEBHOOK] Processing ${gateway} webhook:`, payload);

            // Normalize/parse from gateway
            const result = await this.paymentGateway.handleWebhook(payload, gateway);
            logger.info(`[WEBHOOK] Gateway result:`, result);

            // Find transaction by order_id
            const txSql = `
                SELECT * FROM payment_gateway_transactions
                WHERE order_id = ? AND gateway = ?
            `;

            this.db.get(txSql, [result.order_id, gateway], async (err, transaction) => {
                if (err) {
                    logger.error(`[WEBHOOK] Database error:`, err);
                    return reject(err);
                }

                // Fallback by invoice number
                if (!transaction) {
                    logger.warn(`[WEBHOOK] Transaction not found for order_id: ${result.order_id}`);
                    const invoiceNumber = (result.order_id || '').replace('INV-', '');
                    const fallbackSql = `
                        SELECT i.*
                        FROM invoices i
                        WHERE i.invoice_number = ?
                    `;
                    this.db.get(fallbackSql, [invoiceNumber], async (fbErr, invoice) => {
                        if (fbErr || !invoice) {
                            logger.error(`[WEBHOOK] Fallback search failed:`, fbErr);
                            return reject(new Error('Transaction and invoice not found'));
                        }
                        await this.processDirectPayment(invoice, result, gateway);
                        // Immediate restore for fallback path
                        try {
                            const customer = await this.getCustomerById(invoice.customer_id);
                            if (customer && customer.status === 'suspended') {
                                const invoices = await this.getInvoicesByCustomer(customer.id);
                                const unpaid = invoices.filter(i => i.status === 'unpaid');
                                if (unpaid.length === 0) {
                                    const serviceSuspension = require('./serviceSuspension');
                                    await serviceSuspension.restoreCustomerService(customer);
                                }
                            }
                        } catch (restoreErr) {
                            logger.error('[WEBHOOK] Immediate restore (fallback) failed:', restoreErr);
                        }
                        return resolve({ success: true, message: 'Payment processed via fallback method', invoice_id: invoice.id });
                    });
                    return; // stop here, fallback async handled
                }

                // Update transaction status
                const updateSql = `
                    UPDATE payment_gateway_transactions
                    SET status = ?, payment_type = ?, fraud_status = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                `;
                this.db.run(updateSql, [
                    result.status,
                    result.payment_type || null,
                    result.fraud_status || null,
                    transaction.id
                ], async (updateErr) => {
                    if (updateErr) {
                        logger.error(`[WEBHOOK] Update transaction error:`, updateErr);
                        return reject(updateErr);
                    }

                    if (result.status !== 'success') {
                        logger.info(`[WEBHOOK] Payment status updated: ${result.status}`);
                        return resolve({ success: true, message: 'Payment status updated', status: result.status });
                    }

                    try {
                        logger.info(`[WEBHOOK] Processing successful payment for invoice: ${transaction.invoice_id}`);

                        // Mark invoice paid and record payment
                        await this.updateInvoiceStatus(transaction.invoice_id, 'paid', 'online');
                        const paymentData = {
                            invoice_id: transaction.invoice_id,
                            amount: result.amount || transaction.amount,
                            payment_method: 'online',
                            reference_number: result.order_id,
                            notes: `Payment via ${gateway} - ${result.payment_type || 'online'}`
                        };
                        await this.recordPayment(paymentData);

                        // Notify and restore
                        const invoice = await this.getInvoiceById(transaction.invoice_id);
                        const customer = await this.getCustomerById(invoice.customer_id);
                        if (customer) {
                            try {
                                await this.sendPaymentSuccessNotification(customer, invoice);
                            } catch (notificationError) {
                                logger.error(`[WEBHOOK] Failed send notification:`, notificationError);
                            }
                            try {
                                const refreshed = await this.getCustomerById(invoice.customer_id);
                                if (refreshed && refreshed.status === 'suspended') {
                                    const invoices = await this.getInvoicesByCustomer(refreshed.id);
                                    const unpaid = invoices.filter(i => i.status === 'unpaid');
                                    if (unpaid.length === 0) {
                                        const serviceSuspension = require('./serviceSuspension');
                                        await serviceSuspension.restoreCustomerService(refreshed);
                                    }
                                }
                            } catch (restoreErr) {
                                logger.error('[WEBHOOK] Immediate restore failed:', restoreErr);
                            }
                        } else {
                            logger.error(`[WEBHOOK] Customer not found for invoice: ${transaction.invoice_id}`);
                        }

                        return resolve({ success: true, message: 'Payment processed successfully', invoice_id: transaction.invoice_id });
                    } catch (processingError) {
                        logger.error(`[WEBHOOK] Error in payment processing:`, processingError);
                        return resolve({ success: true, message: 'Payment processed successfully', invoice_id: transaction.invoice_id });
                    }
                });
            });
        } catch (error) {
            logger.error(`[WEBHOOK] Webhook processing error:`, error);
            reject(error);
        }
    });
    }

    async getPaymentTransactions(invoiceId = null) {
        return new Promise((resolve, reject) => {
            let sql = `
                SELECT pgt.*, i.invoice_number, c.name as customer_name
                FROM payment_gateway_transactions pgt
                JOIN invoices i ON pgt.invoice_id = i.id
                JOIN customers c ON i.customer_id = c.id
            `;

            const params = [];
            if (invoiceId) {
                sql += ' WHERE pgt.invoice_id = ?';
                params.push(invoiceId);
            }

            sql += ' ORDER BY pgt.created_at DESC';

            this.db.all(sql, params, (err, rows) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(rows);
                }
            });
        });
    }

    async getGatewayStatus() {
        return this.paymentGateway.getGatewayStatus();
    }

    // Send payment success notification
    async sendPaymentSuccessNotification(customer, invoice) {
        try {
            logger.info(`[NOTIFICATION] Sending payment success notification to ${customer.phone} for invoice ${invoice.invoice_number}`);
            
            const whatsapp = require('./whatsapp');
            
            // Cek apakah WhatsApp sudah terhubung
            const whatsappStatus = whatsapp.getWhatsAppStatus();
            if (!whatsappStatus || !whatsappStatus.connected) {
                logger.warn(`[NOTIFICATION] WhatsApp not connected, status: ${JSON.stringify(whatsappStatus)}`);
                return false;
            }
            
            const message = `🎉 *Pembayaran Berhasil!*

Halo ${customer.name},

Pembayaran tagihan Anda telah berhasil diproses:

📋 *Detail Pembayaran:*
• No. Tagihan: ${invoice.invoice_number}
• Jumlah: Rp ${parseFloat(invoice.amount).toLocaleString('id-ID')}
• Status: LUNAS ✅

Terima kasih telah mempercayai layanan kami.

*ALIJAYA DIGITAL NETWORK*
Info: 081947215703`;

            const result = await whatsapp.sendMessage(customer.phone, message);
            logger.info(`[NOTIFICATION] WhatsApp message sent successfully to ${customer.phone}`);
            return result;
        } catch (error) {
            logger.error(`[NOTIFICATION] Error sending payment success notification to ${customer.phone}:`, error);
            return false;
        }
    }
}

// Create singleton instance
const billingManager = new BillingManager();

module.exports = billingManager; 