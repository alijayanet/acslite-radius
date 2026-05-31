const sqlite3 = require('sqlite3').verbose();
const path = require('path');

const dbPath = path.join(__dirname, '../data/billing.db');
const db = new sqlite3.Database(dbPath);

console.log('Adding payment gateway tables...');

// Create payment_gateway_transactions table
db.run(`
    CREATE TABLE IF NOT EXISTS payment_gateway_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER,
        gateway VARCHAR(50),
        order_id VARCHAR(100),
        payment_url TEXT,
        token VARCHAR(255),
        amount DECIMAL(10,2),
        status VARCHAR(50),
        payment_type VARCHAR(50),
        fraud_status VARCHAR(50),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (invoice_id) REFERENCES invoices(id)
    )
`, function(err) {
    if (err) {
        console.error('Error creating payment_gateway_transactions table:', err);
    } else {
        console.log('✅ payment_gateway_transactions table created successfully');
    }
});

// Add payment gateway columns to invoices table
db.run(`
    ALTER TABLE invoices ADD COLUMN payment_gateway VARCHAR(50)
`, function(err) {
    if (err && !err.message.includes('duplicate column name')) {
        console.error('Error adding payment_gateway column:', err);
    } else {
        console.log('✅ payment_gateway column added to invoices table');
    }
});

db.run(`
    ALTER TABLE invoices ADD COLUMN payment_token VARCHAR(255)
`, function(err) {
    if (err && !err.message.includes('duplicate column name')) {
        console.error('Error adding payment_token column:', err);
    } else {
        console.log('✅ payment_token column added to invoices table');
    }
});

db.run(`
    ALTER TABLE invoices ADD COLUMN payment_url TEXT
`, function(err) {
    if (err && !err.message.includes('duplicate column name')) {
        console.error('Error adding payment_url column:', err);
    } else {
        console.log('✅ payment_url column added to invoices table');
    }
});

db.run(`
    ALTER TABLE invoices ADD COLUMN payment_status VARCHAR(50) DEFAULT 'pending'
`, function(err) {
    if (err && !err.message.includes('duplicate column name')) {
        console.error('Error adding payment_status column:', err);
    } else {
        console.log('✅ payment_status column added to invoices table');
    }
});

// Create index for better performance
db.run(`
    CREATE INDEX IF NOT EXISTS idx_payment_gateway_transactions_invoice_id 
    ON payment_gateway_transactions(invoice_id)
`, function(err) {
    if (err) {
        console.error('Error creating index:', err);
    } else {
        console.log('✅ Index created for payment_gateway_transactions');
    }
});

db.run(`
    CREATE INDEX IF NOT EXISTS idx_payment_gateway_transactions_order_id 
    ON payment_gateway_transactions(order_id)
`, function(err) {
    if (err) {
        console.error('Error creating index:', err);
    } else {
        console.log('✅ Index created for payment_gateway_transactions order_id');
    }
});

console.log('🎉 Payment gateway database setup completed!');
db.close(); 