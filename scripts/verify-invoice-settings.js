#!/usr/bin/env node

/**
 * Script untuk memverifikasi settings invoice
 * Menjalankan: node scripts/verify-invoice-settings.js
 */

const fs = require('fs');
const path = require('path');

// Path ke settings.json
const settingsPath = path.join(__dirname, '../settings.json');

// Field yang diperlukan untuk invoice
const requiredFields = [
    'company_header',
    'payment_bank_name',
    'payment_account_number',
    'payment_account_holder',
    'contact_phone'
];

// Field opsional untuk invoice
const optionalFields = [
    'company_slogan',
    'company_website',
    'invoice_notes',
    'contact_email',
    'contact_address',
    'contact_whatsapp',
    'footer_info',
    'logo_filename'
];

// Field yang digunakan di template
const templateFields = [
    ...requiredFields,
    ...optionalFields
];

function verifySettings() {
    console.log('🔍 Memverifikasi Settings Invoice...\n');
    
    try {
        // Baca settings.json
        const settingsContent = fs.readFileSync(settingsPath, 'utf8');
        const settings = JSON.parse(settingsContent);
        
        console.log('✅ Settings.json berhasil dibaca\n');
        
        // Check required fields
        console.log('📋 Required Fields:');
        let allRequiredPresent = true;
        
        requiredFields.forEach(field => {
            if (settings[field] && settings[field].toString().trim() !== '') {
                console.log(`  ✅ ${field}: "${settings[field]}"`);
            } else {
                console.log(`  ❌ ${field}: MISSING atau KOSONG`);
                allRequiredPresent = false;
            }
        });
        
        console.log('\n📋 Optional Fields:');
        optionalFields.forEach(field => {
            if (settings[field] && settings[field].toString().trim() !== '') {
                console.log(`  ✅ ${field}: "${settings[field]}"`);
            } else {
                console.log(`  ⚠️  ${field}: Tidak ada atau kosong (opsional)`);
            }
        });
        
        // Check logo file
        console.log('\n🖼️  Logo File:');
        const logoPath = path.join(__dirname, '../public/img', settings.logo_filename || 'logo.png');
        if (fs.existsSync(logoPath)) {
            console.log(`  ✅ Logo ditemukan: ${settings.logo_filename || 'logo.png'}`);
        } else {
            console.log(`  ❌ Logo tidak ditemukan: ${settings.logo_filename || 'logo.png'}`);
        }
        
        // Summary
        console.log('\n📊 SUMMARY:');
        if (allRequiredPresent) {
            console.log('  ✅ Semua field required tersedia');
        } else {
            console.log('  ❌ Ada field required yang missing atau kosong');
        }
        
        // Check for unused fields
        console.log('\n🔍 Field yang tidak digunakan di invoice:');
        const allSettingsFields = Object.keys(settings);
        const unusedFields = allSettingsFields.filter(field => !templateFields.includes(field));
        
        if (unusedFields.length > 0) {
            unusedFields.forEach(field => {
                console.log(`  ℹ️  ${field}: Tidak digunakan di template invoice`);
            });
        } else {
            console.log('  ✅ Semua field settings digunakan');
        }
        
        // Recommendations
        console.log('\n💡 RECOMMENDATIONS:');
        if (!settings.company_slogan) {
            console.log('  - Tambahkan company_slogan untuk tagline perusahaan');
        }
        if (!settings.company_website) {
            console.log('  - Tambahkan company_website untuk informasi lengkap');
        }
        if (!settings.invoice_notes) {
            console.log('  - Tambahkan invoice_notes untuk informasi pembayaran');
        }
        if (!settings.contact_email) {
            console.log('  - Tambahkan contact_email untuk komunikasi email');
        }
        if (!settings.contact_address) {
            console.log('  - Tambahkan contact_address untuk alamat kantor');
        }
        
        console.log('\n🎯 Status: ' + (allRequiredPresent ? 'READY' : 'NEEDS ATTENTION'));
        
    } catch (error) {
        console.error('❌ Error membaca settings:', error.message);
        process.exit(1);
    }
}

function showTemplateUsage() {
    console.log('\n📝 TEMPLATE USAGE:');
    console.log('Field yang digunakan di template invoice-print.ejs:');
    
    templateFields.forEach(field => {
        console.log(`  <%= appSettings.${field} %>`);
    });
    
    console.log('\n💡 Tips:');
    console.log('  - Edit settings.json untuk mengubah nilai');
    console.log('  - Tidak perlu restart aplikasi');
    console.log('  - Refresh halaman invoice untuk melihat perubahan');
}

// Main execution
if (require.main === module) {
    verifySettings();
    showTemplateUsage();
}

module.exports = { verifySettings, showTemplateUsage };
