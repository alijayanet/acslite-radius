// RADIUS Server Configuration and Management
const radius = require('radius');
const dgram = require('dgram');
const logger = require('./logger');
const { getSetting, getSettingsWithCache } = require('./settingsManager');
const mysql = require('mysql2/promise');
const billingManager = require('./billing');

class RadiusServer {
    constructor() {
        this.server = null;
        this.port = 1812;
        this.authPort = 1812;
        this.acctPort = 1813;
        this.secret = 'testing123';
        this.isRunning = false;
        this.clients = new Map(); // NAS clients
        this.activeSessions = new Map(); // Active user sessions
    }

    // Initialize RADIUS server configuration
    async initialize() {
        try {
            // Load configuration from settings
            const radiusConfig = getSetting('radius', {});
            this.port = parseInt(radiusConfig.auth_port || getSetting('radius_auth_port', '1812'));
            this.acctPort = parseInt(radiusConfig.acct_port || getSetting('radius_acct_port', '1813'));
            this.secret = radiusConfig.secret || getSetting('radius_secret', 'testing123');
            
            logger.info(`RADIUS config loaded - Port: ${this.port}, Acct Port: ${this.acctPort}, Secret: ${this.secret.replace(/./g, '*')}`);
            
            // Load NAS clients from database/settings
            await this.loadNASClients();
            
            logger.info('RADIUS server configuration initialized');
            return true;
        } catch (error) {
            logger.error(`RADIUS initialization error: ${error.message}`);
            logger.error(`Stack trace: ${error.stack}`);
            return false;
        }
    }

    // Load NAS (Network Access Server) clients
    async loadNASClients() {
        try {
            // Load dari settings.json
            const settings = getSettingsWithCache();
            logger.info(`Loading NAS clients from settings.json`);
            logger.info(`Settings radius_nas_clients: ${JSON.stringify(settings.radius_nas_clients)}`);
            
            if (settings.radius_nas_clients && Array.isArray(settings.radius_nas_clients)) {
                // Clear existing clients
                this.clients.clear();
                logger.info(`Cleared existing NAS clients, loading ${settings.radius_nas_clients.length} clients from settings`);
                
                // Load dari settings.json
                settings.radius_nas_clients.forEach(client => {
                    logger.info(`Adding NAS client: ${client.ip} - ${client.name}`);
                    this.addNASClient(client.ip, client.secret, client.name);
                });
                
                logger.info(`Loaded ${this.clients.size} NAS clients from settings.json`);
            } else {
                logger.warn(`No NAS clients found in settings.json, using defaults`);
                
                // Fallback ke default NAS clients jika settings.json kosong
                this.addNASClient('127.0.0.1', 'testing123', 'Local Mikrotik');
                this.addNASClient('192.168.1.1', 'mikrotik123', 'Main Mikrotik');
                
                // Simpan default clients ke settings.json
                const defaultClients = [
                    { ip: '127.0.0.1', secret: 'testing123', name: 'Local Mikrotik' },
                    { ip: '192.168.1.1', secret: 'mikrotik123', name: 'Main Mikrotik' }
                ];
                const { setSetting } = require('./settingsManager');
                setSetting('radius_nas_clients', defaultClients);
                
                logger.info(`Loaded ${this.clients.size} default NAS clients`);
            }
        } catch (error) {
            logger.error(`Error loading NAS clients: ${error.message}`);
            logger.error(`Stack trace: ${error.stack}`);
            // Fallback ke default clients jika ada error
            this.addNASClient('127.0.0.1', 'testing123', 'Local Mikrotik');
            this.addNASClient('192.168.1.1', 'mikrotik123', 'Main Mikrotik');
        }
    }

    // Add NAS client
    addNASClient(ip, secret, name = '') {
        this.clients.set(ip, {
            secret: secret,
            name: name,
            created: new Date()
        });
    }

    // Remove NAS client
    removeNASClient(ip) {
        return this.clients.delete(ip);
    }

    // Get all NAS clients
    getNASClients() {
        const clients = [];
        for (const [ip, config] of this.clients.entries()) {
            clients.push({
                ip: ip,
                name: config.name,
                secret: config.secret,
                created: config.created
            });
        }
        return clients;
    }

    // Start RADIUS Authentication server
    async startAuthServer() {
        try {
            if (this.server) {
                logger.warn('RADIUS auth server already running');
                return false;
            }

            this.server = dgram.createSocket('udp4');
            
            this.server.on('message', async (msg, rinfo) => {
                await this.handleAuthRequest(msg, rinfo);
            });

            this.server.on('error', (err) => {
                logger.error(`RADIUS auth server error: ${err.message}`);
            });

            this.server.bind(this.port, () => {
                this.isRunning = true;
                logger.info(`RADIUS Authentication server started on port ${this.port}`);
            });

            return true;
        } catch (error) {
            logger.error(`Error starting RADIUS auth server: ${error.message}`);
            return false;
        }
    }

    // Handle authentication request
    async handleAuthRequest(msg, rinfo) {
        try {
            // Get NAS client configuration
            const nasClient = this.clients.get(rinfo.address);
            if (!nasClient) {
                logger.warn(`Unknown NAS client: ${rinfo.address}`);
                return;
            }

            // Decode RADIUS packet
            const packet = radius.decode({
                packet: msg,
                secret: nasClient.secret
            });

            if (!packet) {
                logger.warn(`Invalid RADIUS packet from ${rinfo.address}`);
                return;
            }

            logger.info(`RADIUS request from ${rinfo.address}: ${packet.code}`);

            // Handle different request types
            switch (packet.code) {
                case 'Access-Request':
                    await this.handleAccessRequest(packet, rinfo, nasClient.secret);
                    break;
                case 'Accounting-Request':
                    await this.handleAccountingRequest(packet, rinfo, nasClient.secret);
                    break;
                default:
                    logger.warn(`Unsupported RADIUS request type: ${packet.code}`);
            }
        } catch (error) {
            logger.error(`Error handling RADIUS request: ${error.message}`);
        }
    }

    // Handle Access-Request (Authentication)
    async handleAccessRequest(packet, rinfo, secret) {
        try {
            const username = packet.attributes['User-Name'];
            const password = packet.attributes['User-Password'];
            const nasIP = packet.attributes['NAS-IP-Address'] || rinfo.address;

            logger.info(`Authentication request for user: ${username} from NAS: ${nasIP}`);

            // Authenticate user against billing system
            const isAuthenticated = await this.authenticateUser(username, password);
            
            let response;
            if (isAuthenticated) {
                // Get user profile and attributes
                const userProfile = await this.getUserProfile(username);
                
                response = {
                    code: 'Access-Accept',
                    secret: secret,
                    identifier: packet.identifier,
                    attributes: {
                        'Framed-IP-Address': userProfile.staticIP || '0.0.0.0',
                        'Session-Timeout': userProfile.sessionTimeout || 3600,
                        'Idle-Timeout': userProfile.idleTimeout || 300,
                        'Framed-Protocol': 'PPP',
                        'Service-Type': 'Framed-User'
                    }
                };

                // Add bandwidth attributes if available
                if (userProfile.uploadSpeed) {
                    response.attributes['WISPr-Bandwidth-Max-Up'] = userProfile.uploadSpeed;
                }
                if (userProfile.downloadSpeed) {
                    response.attributes['WISPr-Bandwidth-Max-Down'] = userProfile.downloadSpeed;
                }

                logger.info(`Authentication successful for user: ${username}`);
            } else {
                response = {
                    code: 'Access-Reject',
                    secret: secret,
                    identifier: packet.identifier,
                    attributes: {
                        'Reply-Message': 'Authentication failed'
                    }
                };

                logger.info(`Authentication failed for user: ${username}`);
            }

            // Send response
            const responsePacket = radius.encode(response);
            this.server.send(responsePacket, 0, responsePacket.length, rinfo.port, rinfo.address);

        } catch (error) {
            logger.error(`Error in Access-Request handling: ${error.message}`);
        }
    }

    // Handle Accounting-Request
    async handleAccountingRequest(packet, rinfo, secret) {
        try {
            const username = packet.attributes['User-Name'];
            const sessionId = packet.attributes['Acct-Session-Id'];
            const statusType = packet.attributes['Acct-Status-Type'];

            logger.info(`Accounting request for user: ${username}, session: ${sessionId}, status: ${statusType}`);

            // Handle different accounting status types
            switch (statusType) {
                case 'Start':
                    await this.handleSessionStart(packet);
                    break;
                case 'Stop':
                    await this.handleSessionStop(packet);
                    break;
                case 'Interim-Update':
                    await this.handleSessionUpdate(packet);
                    break;
            }

            // Send Accounting Response
            const response = {
                code: 'Accounting-Response',
                secret: secret,
                identifier: packet.identifier,
                attributes: {}
            };

            const responsePacket = radius.encode(response);
            this.server.send(responsePacket, 0, responsePacket.length, rinfo.port, rinfo.address);

        } catch (error) {
            logger.error(`Error in Accounting-Request handling: ${error.message}`);
        }
    }

    // Authenticate user against billing system
    async authenticateUser(username, password) {
        try {
            // First check billing system customers
            const customer = await billingManager.getCustomerByUsername(username);
            
            if (customer) {
                // Check if password matches (phone number as password)
                if (customer.phone !== password) {
                    logger.info(`Invalid password for customer: ${username}`);
                    return false;
                }

                // Check if customer account is active
                if (customer.status !== 'active') {
                    logger.info(`Customer account inactive: ${username} (status: ${customer.status})`);
                    return false;
                }

                // Check if customer has active subscription
                const hasActiveSubscription = await billingManager.hasActiveSubscription(username);
                if (!hasActiveSubscription) {
                    logger.info(`Customer has no active subscription: ${username}`);
                    return false;
                }

                return true;
            }

            // If not found in customers, check hotspot users
            const hotspotUser = await this.getHotspotUser(username);
            if (hotspotUser) {
                // Check if password matches
                if (hotspotUser.password !== password) {
                    logger.info(`Invalid password for hotspot user: ${username}`);
                    return false;
                }

                // Check if hotspot user is active
                if (hotspotUser.status !== 'active') {
                    logger.info(`Hotspot user account inactive: ${username} (status: ${hotspotUser.status})`);
                    return false;
                }

                return true;
            }

            logger.info(`User not found in any system: ${username}`);
            return false;
        } catch (error) {
            logger.error(`Error authenticating user ${username}: ${error.message}`);
            return false;
        }
    }

    // Get hotspot user from database
    async getHotspotUser(username) {
        try {
            return new Promise((resolve, reject) => {
                const query = 'SELECT * FROM hotspot_users WHERE username = ? AND status = "active"';
                billingManager.db.get(query, [username], (err, row) => {
                    if (err) {
                        logger.error(`Database error getting hotspot user: ${err.message}`);
                        reject(err);
                    } else {
                        resolve(row);
                    }
                });
            });
        } catch (error) {
            logger.error(`Error getting hotspot user ${username}: ${error.message}`);
            return null;
        }
    }

    // Add hotspot user to database
    async addHotspotUser(username, password, profile = 'default', createdBy = 'system') {
        try {
            return new Promise((resolve, reject) => {
                const query = `
                    INSERT INTO hotspot_users (username, password, profile, status, created_at, created_by)
                    VALUES (?, ?, ?, 'active', datetime('now'), ?)
                `;
                billingManager.db.run(query, [username, password, profile, createdBy], function(err) {
                    if (err) {
                        if (err.code === 'SQLITE_CONSTRAINT') {
                            logger.warn(`Hotspot user already exists: ${username}`);
                            resolve({ success: false, message: 'Username sudah digunakan' });
                        } else {
                            logger.error(`Database error adding hotspot user: ${err.message}`);
                            reject(err);
                        }
                    } else {
                        logger.info(`Hotspot user added: ${username} (ID: ${this.lastID})`);
                        resolve({ 
                            success: true, 
                            message: 'User hotspot berhasil ditambahkan',
                            id: this.lastID
                        });
                    }
                });
            });
        } catch (error) {
            logger.error(`Error adding hotspot user ${username}: ${error.message}`);
            return { success: false, message: `Error: ${error.message}` };
        }
    }

    // Get all hotspot users
    async getHotspotUsers() {
        try {
            return new Promise((resolve, reject) => {
                const query = 'SELECT * FROM hotspot_users ORDER BY created_at DESC';
                billingManager.db.all(query, [], (err, rows) => {
                    if (err) {
                        logger.error(`Database error getting hotspot users: ${err.message}`);
                        reject(err);
                    } else {
                        resolve(rows || []);
                    }
                });
            });
        } catch (error) {
            logger.error(`Error getting hotspot users: ${error.message}`);
            return [];
        }
    }

    // Delete hotspot user
    async deleteHotspotUser(username) {
        try {
            return new Promise((resolve, reject) => {
                const query = 'DELETE FROM hotspot_users WHERE username = ?';
                billingManager.db.run(query, [username], function(err) {
                    if (err) {
                        logger.error(`Database error deleting hotspot user: ${err.message}`);
                        reject(err);
                    } else if (this.changes === 0) {
                        resolve({ success: false, message: 'User tidak ditemukan' });
                    } else {
                        logger.info(`Hotspot user deleted: ${username}`);
                        resolve({ success: true, message: 'User hotspot berhasil dihapus' });
                    }
                });
            });
        } catch (error) {
            logger.error(`Error deleting hotspot user ${username}: ${error.message}`);
            return { success: false, message: `Error: ${error.message}` };
        }
    }

    // Get user profile and attributes
    async getUserProfile(username) {
        try {
            // Try customer first
            const customer = await billingManager.getCustomerByUsername(username);
            if (customer) {
                const profile = {
                    username: username,
                    staticIP: customer.static_ip || null,
                sessionTimeout: 3600, // 1 hour default
                idleTimeout: 300, // 5 minutes default
                uploadSpeed: null,
                downloadSpeed: null
            };

            // Get package details for bandwidth limits
            if (customer.package_id) {
                const packageDetails = await billingManager.getPackageDetails(customer.package_id);
                if (packageDetails) {
                    // Convert Mbps to bits per second
                    profile.uploadSpeed = packageDetails.upload_speed ? packageDetails.upload_speed * 1000000 : null;
                    profile.downloadSpeed = packageDetails.download_speed ? packageDetails.download_speed * 1000000 : null;
                }
            }

                return profile;
            }

            // If not found in customers, try hotspot users
            const hotspotUser = await this.getHotspotUser(username);
            if (hotspotUser) {
                return {
                    username: username,
                    staticIP: null,
                    sessionTimeout: 3600, // 1 hour default for hotspot
                    idleTimeout: 300, // 5 minutes default
                    uploadSpeed: null,
                    downloadSpeed: null,
                    profile: hotspotUser.profile || 'default'
                };
            }

            // Default profile if user not found
            return {
                username: username,
                sessionTimeout: 3600,
                idleTimeout: 300,
                profile: 'default'
            };
        } catch (error) {
            logger.error(`Error getting user profile for ${username}: ${error.message}`);
            return {
                username: username,
                sessionTimeout: 3600,
                idleTimeout: 300
            };
        }
    }

    // Handle session start
    async handleSessionStart(packet) {
        try {
            const sessionData = {
                username: packet.attributes['User-Name'],
                sessionId: packet.attributes['Acct-Session-Id'],
                nasIP: packet.attributes['NAS-IP-Address'],
                framedIP: packet.attributes['Framed-IP-Address'],
                startTime: new Date(),
                inputOctets: 0,
                outputOctets: 0,
                inputPackets: 0,
                outputPackets: 0
            };

            this.activeSessions.set(sessionData.sessionId, sessionData);
            logger.info(`Session started for user: ${sessionData.username}, session: ${sessionData.sessionId}`);

            // Log to database (optional)
            // await this.logSessionToDatabase('start', sessionData);

        } catch (error) {
            logger.error(`Error handling session start: ${error.message}`);
        }
    }

    // Handle session stop
    async handleSessionStop(packet) {
        try {
            const sessionId = packet.attributes['Acct-Session-Id'];
            const session = this.activeSessions.get(sessionId);

            if (session) {
                session.stopTime = new Date();
                session.sessionTime = packet.attributes['Acct-Session-Time'] || 0;
                session.inputOctets = packet.attributes['Acct-Input-Octets'] || 0;
                session.outputOctets = packet.attributes['Acct-Output-Octets'] || 0;
                session.inputPackets = packet.attributes['Acct-Input-Packets'] || 0;
                session.outputPackets = packet.attributes['Acct-Output-Packets'] || 0;

                logger.info(`Session stopped for user: ${session.username}, session: ${sessionId}, duration: ${session.sessionTime}s`);

                // Log to database (optional)
                // await this.logSessionToDatabase('stop', session);

                this.activeSessions.delete(sessionId);
            }

        } catch (error) {
            logger.error(`Error handling session stop: ${error.message}`);
        }
    }

    // Handle session update
    async handleSessionUpdate(packet) {
        try {
            const sessionId = packet.attributes['Acct-Session-Id'];
            const session = this.activeSessions.get(sessionId);

            if (session) {
                session.sessionTime = packet.attributes['Acct-Session-Time'] || 0;
                session.inputOctets = packet.attributes['Acct-Input-Octets'] || 0;
                session.outputOctets = packet.attributes['Acct-Output-Octets'] || 0;
                session.inputPackets = packet.attributes['Acct-Input-Packets'] || 0;
                session.outputPackets = packet.attributes['Acct-Output-Packets'] || 0;

                logger.debug(`Session update for user: ${session.username}, session: ${sessionId}`);
            }

        } catch (error) {
            logger.error(`Error handling session update: ${error.message}`);
        }
    }

    // Get active sessions
    getActiveSessions() {
        const sessions = [];
        for (const [sessionId, session] of this.activeSessions.entries()) {
            sessions.push({
                sessionId: sessionId,
                username: session.username,
                nasIP: session.nasIP,
                framedIP: session.framedIP,
                startTime: session.startTime,
                duration: session.sessionTime || 0,
                inputOctets: session.inputOctets || 0,
                outputOctets: session.outputOctets || 0
            });
        }
        return sessions;
    }

    // Stop RADIUS server
    async stop() {
        try {
            if (this.server) {
                this.server.close();
                this.server = null;
                this.isRunning = false;
                logger.info('RADIUS server stopped');
                return true;
            }
            return false;
        } catch (error) {
            logger.error(`Error stopping RADIUS server: ${error.message}`);
            return false;
        }
    }

    // Get server status
    getStatus() {
        return {
            running: this.isRunning,
            port: this.port,
            acctPort: this.acctPort,
            activeClients: this.clients.size,
            activeSessions: this.activeSessions.size,
            secret: this.secret.replace(/./g, '*') // Hide secret
        };
    }

    // Reload NAS clients from settings.json
    async reloadNASClients() {
        try {
            logger.info('Starting NAS clients reload process...');
            await this.loadNASClients();
            logger.info('NAS clients reloaded from settings.json successfully');
            logger.info(`Current NAS clients count: ${this.clients.size}`);
            return true;
        } catch (error) {
            logger.error(`Error reloading NAS clients: ${error.message}`);
            logger.error(`Stack trace: ${error.stack}`);
            return false;
        }
    }
}

// Create singleton instance
const radiusServer = new RadiusServer();

module.exports = {
    radiusServer,
    RadiusServer
};
