const sqlite3 = require('sqlite3').verbose();
const path = require('path');
const { logger } = require('../config/logger');

// Script untuk menambahkan kolom pppoe_username ke tabel customers
async function addPPPoEColumn() {
    const dbPath = path.join(__dirname, '../data/billing.db');
    const db = new sqlite3.Database(dbPath);

    return new Promise((resolve, reject) => {
        // Cek apakah kolom sudah ada
        db.get("PRAGMA table_info(customers)", (err, rows) => {
            if (err) {
                logger.error('Error checking table structure:', err);
                reject(err);
                return;
            }

            // Cek apakah kolom pppoe_username sudah ada
            db.all("PRAGMA table_info(customers)", (err, columns) => {
                if (err) {
                    logger.error('Error getting table info:', err);
                    reject(err);
                    return;
                }

                const hasPPPoEColumn = columns.some(col => col.name === 'pppoe_username');
                
                if (hasPPPoEColumn) {
                    logger.info('Column pppoe_username already exists');
                    db.close();
                    resolve();
                    return;
                }

                // Tambahkan kolom pppoe_username
                db.run("ALTER TABLE customers ADD COLUMN pppoe_username TEXT", (err) => {
                    if (err) {
                        logger.error('Error adding pppoe_username column:', err);
                        reject(err);
                        return;
                    }

                    logger.info('Successfully added pppoe_username column to customers table');
                    db.close();
                    resolve();
                });
            });
        });
    });
}

// Jalankan script jika dipanggil langsung
if (require.main === module) {
    addPPPoEColumn()
        .then(() => {
            console.log('Migration completed successfully');
            process.exit(0);
        })
        .catch((error) => {
            console.error('Migration failed:', error);
            process.exit(1);
        });
}

module.exports = { addPPPoEColumn }; 