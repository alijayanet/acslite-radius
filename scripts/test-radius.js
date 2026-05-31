const radius = require('radius');
const dgram = require('dgram');
const { getSetting } = require('../config/settingsManager');

console.log('🔍 RADIUS Server Analysis and Testing\n');
console.log('='.repeat(60));

// Test RADIUS server configuration
function testRadiusConfig() {
    console.log('\n📋 RADIUS CONFIGURATION:');
    
    const radiusConfig = getSetting('radius', {});
    const authPort = parseInt(radiusConfig.auth_port || getSetting('radius_auth_port', '1812'));
    const acctPort = parseInt(radiusConfig.acct_port || getSetting('radius_acct_port', '1813'));
    const secret = radiusConfig.secret || getSetting('radius_secret', 'testing123');
    const enabled = radiusConfig.enabled || false;
    const host = radiusConfig.host || getSetting('radius_host', '0.0.0.0');
    
    console.log(`   ✓ RADIUS Enabled: ${enabled}`);
    console.log(`   ✓ Authentication Port: ${authPort}`);
    console.log(`   ✓ Accounting Port: ${acctPort}`);
    console.log(`   ✓ Host: ${host}`);
    console.log(`   ✓ Secret: ${'*'.repeat(secret.length)} (${secret.length} chars)`);
    
    // Check NAS clients configuration
    const nasClients = getSetting('radius_nas_clients', []);
    console.log(`   ✓ NAS Clients: ${nasClients.length} configured`);
    
    if (nasClients.length > 0) {
        console.log('     NAS Client Details:');
        nasClients.forEach((client, index) => {
            console.log(`       ${index + 1}. ${client.name} (${client.ip}) - Secret: ${'*'.repeat(client.secret.length)} chars`);
        });
    }
    
    return { authPort, acctPort, secret, enabled, host, nasClients };
}

// Test RADIUS packet creation
function testRadiusPackets() {
    console.log('\n📦 RADIUS PACKET TESTING:');
    
    try {
        // Test Access-Request packet creation
        console.log('   ✓ Testing Access-Request packet creation...');
        const accessRequest = {
            code: 'Access-Request',
            secret: 'testing123',
            identifier: 1,
            attributes: {
                'User-Name': 'testuser',
                'User-Password': 'testpass',
                'NAS-IP-Address': '192.168.1.1',
                'Service-Type': 'Framed-User',
                'Framed-Protocol': 'PPP'
            }
        };
        
        const packet = radius.encode(accessRequest);
        console.log(`     - Packet size: ${packet.length} bytes`);
        console.log(`     - Packet created successfully`);
        
        // Test packet decoding
        console.log('   ✓ Testing packet decoding...');
        const decoded = radius.decode({
            packet: packet,
            secret: 'testing123'
        });
        
        if (decoded && decoded.attributes['User-Name'] === 'testuser') {
            console.log('     - Packet decoded successfully');
            console.log(`     - Username: ${decoded.attributes['User-Name']}`);
        } else {
            console.log('     ❌ Packet decoding failed');
        }
        
        // Test Accounting-Request packet
        console.log('   ✓ Testing Accounting-Request packet creation...');
        const accountingRequest = {
            code: 'Accounting-Request',
            secret: 'testing123',
            identifier: 2,
            attributes: {
                'User-Name': 'testuser',
                'Acct-Status-Type': 'Start',
                'Acct-Session-Id': 'test-session-123',
                'NAS-IP-Address': '192.168.1.1',
                'Framed-IP-Address': '192.168.100.10'
            }
        };
        
        const acctPacket = radius.encode(accountingRequest);
        console.log(`     - Accounting packet size: ${acctPacket.length} bytes`);
        console.log(`     - Accounting packet created successfully`);
        
    } catch (error) {
        console.log(`   ❌ RADIUS packet testing error: ${error.message}`);
    }
}

// Test RADIUS server module
async function testRadiusServerModule() {
    console.log('\n🖥️ RADIUS SERVER MODULE TESTING:');
    
    try {
        const { radiusServer } = require('../config/radius');
        
        console.log('   ✓ RADIUS server module loaded successfully');
        
        // Test initialization
        const initResult = await radiusServer.initialize();
        console.log(`   ✓ Initialization: ${initResult ? 'Success' : 'Failed'}`);
        
        // Test status
        const status = radiusServer.getStatus();
        console.log(`   ✓ Server Status:`);
        console.log(`     - Running: ${status.running}`);
        console.log(`     - Auth Port: ${status.port}`);
        console.log(`     - Acct Port: ${status.acctPort}`);
        console.log(`     - Active Clients: ${status.activeClients}`);
        console.log(`     - Active Sessions: ${status.activeSessions}`);
        
        // Test NAS client management
        console.log('   ✓ Testing NAS client management...');
        const nasClients = radiusServer.getNASClients();
        console.log(`     - NAS clients loaded: ${nasClients.length}`);
        
        if (nasClients.length > 0) {
            console.log('     - NAS client details:');
            nasClients.forEach((client, index) => {
                console.log(`       ${index + 1}. ${client.name} (${client.ip})`);
            });
        }
        
        // Test active sessions
        const activeSessions = radiusServer.getActiveSessions();
        console.log(`     - Active sessions: ${activeSessions.length}`);
        
    } catch (error) {
        console.log(`   ❌ RADIUS server module testing error: ${error.message}`);
        console.log(`   Stack trace: ${error.stack}`);
    }
}

// Test authentication flow
async function testAuthenticationFlow() {
    console.log('\n🔐 AUTHENTICATION FLOW TESTING:');
    
    try {
        const { radiusServer } = require('../config/radius');
        
        // Test hotspot user authentication
        console.log('   ✓ Testing hotspot user authentication...');
        const isValidHotspot = await radiusServer.authenticateUser('demo', 'demo123');
        console.log(`     - Hotspot user 'demo': ${isValidHotspot ? 'Valid' : 'Invalid'}`);
        
        const isValidHotspotWrong = await radiusServer.authenticateUser('demo', 'wrongpass');
        console.log(`     - Hotspot user 'demo' (wrong pass): ${isValidHotspotWrong ? 'Valid' : 'Invalid'}`);
        
        // Test non-existent user
        const isValidNonExistent = await radiusServer.authenticateUser('nonexistent', 'password');
        console.log(`     - Non-existent user: ${isValidNonExistent ? 'Valid' : 'Invalid'}`);
        
        // Test user profile retrieval
        console.log('   ✓ Testing user profile retrieval...');
        const profile = await radiusServer.getUserProfile('demo');
        console.log(`     - Profile for 'demo': ${JSON.stringify(profile, null, 2)}`);
        
    } catch (error) {
        console.log(`   ❌ Authentication flow testing error: ${error.message}`);
    }
}

// Test hotspot user management
async function testHotspotUserManagement() {
    console.log('\n👥 HOTSPOT USER MANAGEMENT TESTING:');
    
    try {
        const { radiusServer } = require('../config/radius');
        
        // Get existing users
        console.log('   ✓ Getting existing hotspot users...');
        const users = await radiusServer.getHotspotUsers();
        console.log(`     - Total hotspot users: ${users.length}`);
        
        if (users.length > 0) {
            console.log('     - User details:');
            users.forEach((user, index) => {
                console.log(`       ${index + 1}. ${user.username} (${user.profile}) - Status: ${user.status}`);
            });
        }
        
        // Test adding a new user (with unique name to avoid conflicts)
        const testUsername = `radiustest_${Date.now()}`;
        console.log(`   ✓ Testing user creation: ${testUsername}...`);
        const addResult = await radiusServer.addHotspotUser(testUsername, 'testpass123', 'default', 'system_test');
        
        if (addResult.success) {
            console.log(`     - User created successfully: ${addResult.message}`);
            
            // Test deleting the user
            console.log(`   ✓ Testing user deletion: ${testUsername}...`);
            const deleteResult = await radiusServer.deleteHotspotUser(testUsername);
            
            if (deleteResult.success) {
                console.log(`     - User deleted successfully: ${deleteResult.message}`);
            } else {
                console.log(`     - User deletion failed: ${deleteResult.message}`);
            }
        } else {
            console.log(`     - User creation failed: ${addResult.message}`);
        }
        
    } catch (error) {
        console.log(`   ❌ Hotspot user management testing error: ${error.message}`);
    }
}

// Main test function
async function runRadiusTests() {
    try {
        testRadiusConfig();
        testRadiusPackets();
        await testRadiusServerModule();
        await testAuthenticationFlow();
        await testHotspotUserManagement();
        
        console.log('\n✅ RADIUS server analysis completed successfully!');
        
    } catch (error) {
        console.error('\n❌ Error during RADIUS analysis:', error.message);
        console.error('Stack trace:', error.stack);
    }
}

// Run tests
runRadiusTests();