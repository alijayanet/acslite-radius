const sqlite3 = require('sqlite3').verbose();
const path = require('path');

// Database path
const dbPath = path.join(__dirname, '..', 'data', 'billing.db');

// Connect to database
const db = new sqlite3.Database(dbPath, (err) => {
    if (err) {
        console.error('Error connecting to database:', err.message);
        process.exit(1);
    }
    console.log('Connected to SQLite database');
});

// Create hotspot_users table
const createTableQuery = `
CREATE TABLE IF NOT EXISTS hotspot_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    profile TEXT DEFAULT 'default',
    status TEXT DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by TEXT DEFAULT 'system',
    last_login DATETIME NULL,
    notes TEXT NULL
)`;

db.run(createTableQuery, (err) => {
    if (err) {
        console.error('Error creating hotspot_users table:', err.message);
        process.exit(1);
    }
    console.log('✅ hotspot_users table created successfully');
    
    // Add sample data for testing
    const sampleUsers = [
        ['demo', 'demo123', 'default', 'active', 'system'],
        ['guest', 'guest123', 'guest', 'active', 'system'],
        ['test', 'test123', 'default', 'active', 'system']
    ];
    
    console.log('Adding sample hotspot users...');
    
    const insertQuery = `
        INSERT OR IGNORE INTO hotspot_users (username, password, profile, status, created_by)
        VALUES (?, ?, ?, ?, ?)
    `;
    
    let completed = 0;
    sampleUsers.forEach((user, index) => {
        db.run(insertQuery, user, function(err) {
            if (err) {
                console.error(`Error adding user ${user[0]}:`, err.message);
            } else {
                console.log(`✅ Added hotspot user: ${user[0]} (profile: ${user[2]})`);
            }
            
            completed++;
            if (completed === sampleUsers.length) {
                console.log('\n🎉 Hotspot users table setup completed!');
                console.log('\n📝 Sample users created:');
                console.log('• Username: demo, Password: demo123, Profile: default');
                console.log('• Username: guest, Password: guest123, Profile: guest');
                console.log('• Username: test, Password: test123, Profile: default');
                console.log('\n🚀 You can now use these for testing hotspot authentication.');
                
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
