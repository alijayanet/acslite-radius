# Changelog - ACSLite-Radius

All notable changes to ACSLite-Radius will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] - 2026-01-14

### Added - Telegram Bot Enhancements
- ✅ Added complete interactive menu system for Telegram bot
- ✅ Added 8 main menus with navigation (Pelanggan, Invoice, Pembayaran, Paket, MikroTik PPPoE, Hotspot, MikroTik Tools, Dashboard)
- ✅ Added 40+ text commands for quick access
- ✅ Added 10+ MikroTik tools features:
  - Resource Monitor (CPU, RAM, Disk, Uptime)
  - Ping Test with packet loss statistics
  - Interface Status
  - Log Viewer
  - Traffic Monitor per interface
  - DHCP Leases
  - Firewall Rules
  - Wireless Scan
  - Router Reboot
  - Config Backup
- ✅ Added payment management functions (list, daily/monthly reports)
- ✅ Added package listing function
- ✅ Added hotspot active users listing
- ✅ Added hotspot statistics dashboard
- ✅ Added inline keyboard builders for all menus
- ✅ Added callback handlers for all menu interactions

### Added - Documentation
- ✅ Created TELEGRAM_BOT_DESIGN.md - Complete menu design and structure
- ✅ Created TELEGRAM_BOT_GUIDE.md - User guide with all commands and examples

### Improved - Telegram Webhook
- ✅ Enhanced webhook.php with all new menu handlers
- ✅ Added MikroTik API integration for tools
- ✅ Added database integration for payment/package/hotspot stats
- ✅ Improved error handling and user feedback

### Fixed
- ✅ Fixed duplicate callback handlers in telegram_webhook.php
- ✅ Improved keyboard layout consistency

### Technical Details
- File: `web/api/telegram_webhook.php` (1731 lines)
- File: `TELEGRAM_BOT_DESIGN.md` (223 lines)
- File: `TELEGRAM_BOT_GUIDE.md` (416 lines)
- All changes are backward compatible with existing configuration

---

## [2.0.0] - Previous Release

### Features
- Go-ACS TR-069 Server
- FreeRADIUS Integration
- Billing System (Customers, Invoices, Payments)
- Hotspot Voucher System
- MikroTik Integration
- Telegram Notifications (Basic)
- Web Admin Dashboard
- Database Admin
- Auto-isolir System
- Auto-invoice Generation
- System Updater

---

## Version Format

Format: `MAJOR.MINOR.PATCH`

- **MAJOR**: Incompatible API changes
- **MINOR**: New functionality (backwards compatible)
- **PATCH**: Bug fixes (backwards compatible)

---

## Notes

- All Telegram bot features can be configured via Settings page
- Webhook URL must be HTTPS with valid SSL
- Protected files during update: `.credentials.php`, `admin.json`, `mikrotik.json`, `config.json`, `.htaccess`
- Telegram bot token and chat ID are stored in database `telegram_config` table
