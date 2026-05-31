const sqlite3 = require('sqlite3').verbose();
const path = require('path');

// Database path
const dbPath = path.join(__dirname, '..', 'data', 'billing.db');

console.log('🔧 Adding Customer Device Mapping table...');

// Connect to database
const db = new sqlite3.Database(dbPath, (err) => {
    if (err) {
        console.error('Error connecting to database:', err.message);
        process.exit(1);
    }
    console.log('Connected to SQLite database');
});

// Create customer_device_mappings table
const createTableQuery = `
CREATE TABLE IF NOT EXISTS customer_device_mappings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL,
    device_id TEXT NOT NULL,
    device_name TEXT,
    device_serial TEXT,
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    UNIQUE(customer_id, device_id)
)`;

db.run(createTableQuery, (err) => {
    if (err) {
        console.error('Error creating customer_device_mappings table:', err.message);
        process.exit(1);
    }
    console.log('✅ customer_device_mappings table created successfully');
    
    // Create index untuk performa yang lebih baik
    const indexQueries = [
        'CREATE INDEX IF NOT EXISTS idx_customer_device_mappings_customer_id ON customer_device_mappings(customer_id)',
        'CREATE INDEX IF NOT EXISTS idx_customer_device_mappings_device_id ON customer_device_mappings(device_id)',
        'CREATE INDEX IF NOT EXISTS idx_customer_device_mappings_created_at ON customer_device_mappings(created_at)'
    ];
    
    let completed = 0;
    indexQueries.forEach((indexQuery, index) => {
        db.run(indexQuery, (err) => {
            if (err) {
                console.error(`Error creating index ${index + 1}:`, err.message);
            } else {
                console.log(`✅ Index ${index + 1} created successfully`);
            }
            
            completed++;
            if (completed === indexQueries.length) {
                console.log('\n🎉 Customer Device Mapping table setup completed!');
                console.log('\n📋 Table Structure:');
                console.log('• id - Primary key');
                console.log('• customer_id - Foreign key ke customers table');
                console.log('• device_id - ID device dari GenieACS');
                console.log('• device_name - Nama device (optional)');
                console.log('• device_serial - Serial number device (optional)');
                console.log('• notes - Catatan tambahan');
                console.log('• created_at/updated_at - Timestamps');
                console.log('\n🚀 Ready untuk customer device mapping!');
                
                db.close((err) => {
                    if (err) {
                        console.error('Error closing database:', err.message);
                    } else {
                        console.log('Database connection closed.');
                    }
                    process.exit(0);
                });
            }
        });
    });
});