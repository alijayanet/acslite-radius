const sqlite3 = require('sqlite3').verbose();
const path = require('path');

const dbPath = path.join(__dirname, '../data/billing.db');

console.log('🔍 Examining GEMBOK-RADIUS Database Structure and Integrity\n');
console.log('='.repeat(60));

const db = new sqlite3.Database(dbPath, sqlite3.OPEN_READONLY, (err) => {
    if (err) {
        console.error('❌ Error opening database:', err.message);
        process.exit(1);
    }
    console.log('✅ Connected to SQLite database');
});

// Function to get table schema
function getTableSchema(tableName) {
    return new Promise((resolve, reject) => {
        db.all(`PRAGMA table_info(${tableName})`, (err, rows) => {
            if (err) {
                reject(err);
            } else {
                resolve(rows);
            }
        });
    });
}

// Function to get table count
function getTableCount(tableName) {
    return new Promise((resolve, reject) => {
        db.get(`SELECT COUNT(*) as count FROM ${tableName}`, (err, row) => {
            if (err) {
                reject(err);
            } else {
                resolve(row.count);
            }
        });
    });
}

// Function to get all tables
function getAllTables() {
    return new Promise((resolve, reject) => {
        db.all("SELECT name FROM sqlite_master WHERE type='table'", (err, rows) => {
            if (err) {
                reject(err);
            } else {
                resolve(rows.map(row => row.name));
            }
        });
    });
}

// Main analysis function
async function analyzeDatabase() {
    try {
        console.log('\n📋 DATABASE TABLES:');
        const tables = await getAllTables();
        console.log(`Found ${tables.length} tables:`, tables.join(', '));
        
        console.log('\n📊 TABLE ANALYSIS:');
        
        for (const table of tables) {
            console.log(`\n🔍 Table: ${table}`);
            
            // Get schema
            const schema = await getTableSchema(table);
            console.log('   Columns:');
            schema.forEach(col => {
                const nullable = col.notnull ? 'NOT NULL' : 'NULLABLE';
                const defaultVal = col.dflt_value ? `DEFAULT ${col.dflt_value}` : '';
                const pk = col.pk ? '(PK)' : '';
                console.log(`     - ${col.name} ${col.type} ${nullable} ${defaultVal} ${pk}`.trim());
            });
            
            // Get count
            try {
                const count = await getTableCount(table);
                console.log(`   Records: ${count}`);
            } catch (e) {
                console.log(`   Records: Error getting count - ${e.message}`);
            }
        }
        
        // Check for foreign key integrity
        console.log('\n🔗 FOREIGN KEY CONSTRAINTS:');
        for (const table of tables) {
            await new Promise((resolve) => {
                db.all(`PRAGMA foreign_key_list(${table})`, (err, fks) => {
                    if (err) {
                        console.log(`   ${table}: Error checking FKs - ${err.message}`);
                    } else if (fks.length > 0) {
                        console.log(`   ${table}:`);
                        fks.forEach(fk => {
                            console.log(`     - ${fk.from} -> ${fk.table}.${fk.to}`);
                        });
                    }
                    resolve();
                });
            });
        }
        
        // Check for indexes
        console.log('\n📇 INDEXES:');
        await new Promise((resolve) => {
            db.all("SELECT name, tbl_name FROM sqlite_master WHERE type='index' AND name NOT LIKE 'sqlite_%'", (err, indexes) => {
                if (err) {
                    console.log('   Error getting indexes:', err.message);
                } else if (indexes.length > 0) {
                    indexes.forEach(idx => {
                        console.log(`   - ${idx.name} on ${idx.tbl_name}`);
                    });
                } else {
                    console.log('   No custom indexes found');
                }
                resolve();
            });
        });
        
        // Sample data check
        console.log('\n📄 SAMPLE DATA VERIFICATION:');
        
        // Check customers table
        if (tables.includes('customers')) {
            await new Promise((resolve) => {
                db.get("SELECT COUNT(*) as count FROM customers WHERE status = 'active'", (err, row) => {
                    if (!err && row) {
                        console.log(`   Active customers: ${row.count}`);
                    }
                    resolve();
                });
            });
        }
        
        // Check hotspot_users table
        if (tables.includes('hotspot_users')) {
            await new Promise((resolve) => {
                db.get("SELECT COUNT(*) as count FROM hotspot_users WHERE status = 'active'", (err, row) => {
                    if (!err && row) {
                        console.log(`   Active hotspot users: ${row.count}`);
                    }
                    resolve();
                });
            });
        }
        
        // Check invoices table
        if (tables.includes('invoices')) {
            await new Promise((resolve) => {
                db.all("SELECT status, COUNT(*) as count FROM invoices GROUP BY status", (err, rows) => {
                    if (!err && rows) {
                        console.log('   Invoice status distribution:');
                        rows.forEach(row => {
                            console.log(`     - ${row.status}: ${row.count}`);
                        });
                    }
                    resolve();
                });
            });
        }
        
        console.log('\n✅ Database analysis completed successfully!');
        
    } catch (error) {
        console.error('❌ Error during analysis:', error.message);
    } finally {
        db.close((err) => {
            if (err) {
                console.error('Error closing database:', err.message);
            } else {
                console.log('🔐 Database connection closed.');
            }
        });
    }
}

// Run analysis
analyzeDatabase();