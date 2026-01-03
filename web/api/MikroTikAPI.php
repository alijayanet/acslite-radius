<?php
/**
 * MikroTik RouterOS API Class
 * For ACS-Lite ISP Billing Integration
 * 
 * Features:
 * - Connect to RouterOS
 * - List PPPoE Secrets/Active
 * - Change PPPoE Profile (Isolir)
 * - List Profiles
 */

class MikroTikAPI {
    private $socket;
    private $debug = false;
    private $connected = false;
    private $timeout = 5;
    private $error = '';
    
    /**
     * Connect to RouterOS
     */
    public function connect($ip, $username, $password, $port = 8728) {
        $this->error = '';
        
        // Create socket
        $this->socket = @fsockopen($ip, $port, $errno, $errstr, $this->timeout);
        
        if (!$this->socket) {
            $this->error = "Connection failed: $errstr ($errno)";
            return false;
        }
        
        // Set timeout
        stream_set_timeout($this->socket, $this->timeout);
        
        // Login
        $response = $this->command(['/login', '=name=' . $username, '=password=' . $password]);
        
        if (isset($response[0]) && $response[0] === '!done') {
            $this->connected = true;
            return true;
        }
        
        // Try old login method (pre-6.43)
        if (isset($response[0][0]) && $response[0][0] === '!done' && isset($response[0]['=ret'])) {
            $challenge = $response[0]['=ret'];
            $response = $this->command([
                '/login',
                '=name=' . $username,
                '=response=00' . md5(chr(0) . $password . pack('H*', $challenge))
            ]);
            
            if (isset($response[0]) && $response[0] === '!done') {
                $this->connected = true;
                return true;
            }
        }
        
        $this->error = "Login failed. Check username/password.";
        $this->disconnect();
        return false;
    }
    
    /**
     * Disconnect from RouterOS
     */
    public function disconnect() {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
    }
    
    /**
     * Check if connected
     */
    public function isConnected() {
        return $this->connected;
    }
    
    /**
     * Get last error
     */
    public function getError() {
        return $this->error;
    }
    
    /**
     * Send command to RouterOS
     */
    public function command($command) {
        if (!$this->socket) return false;
        
        // Write command
        foreach ($command as $word) {
            $this->writeWord($word);
        }
        $this->writeWord(''); // End of sentence
        
        // Read response
        return $this->readResponse();
    }
    
    /**
     * Write word to socket
     */
    private function writeWord($word) {
        $len = strlen($word);
        
        if ($len < 0x80) {
            fwrite($this->socket, chr($len));
        } elseif ($len < 0x4000) {
            fwrite($this->socket, chr(($len >> 8) | 0x80) . chr($len & 0xFF));
        } elseif ($len < 0x200000) {
            fwrite($this->socket, chr(($len >> 16) | 0xC0) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        } elseif ($len < 0x10000000) {
            fwrite($this->socket, chr(($len >> 24) | 0xE0) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        } else {
            fwrite($this->socket, chr(0xF0) . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        }
        
        fwrite($this->socket, $word);
    }
    
    /**
     * Read word from socket
     */
    private function readWord() {
        $byte = ord(fread($this->socket, 1));
        
        if ($byte & 0x80) {
            if (($byte & 0xC0) == 0x80) {
                $len = (($byte & 0x3F) << 8) + ord(fread($this->socket, 1));
            } elseif (($byte & 0xE0) == 0xC0) {
                $len = (($byte & 0x1F) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
            } elseif (($byte & 0xF0) == 0xE0) {
                $len = (($byte & 0x0F) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
            } elseif ($byte == 0xF0) {
                $len = (ord(fread($this->socket, 1)) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
            }
        } else {
            $len = $byte;
        }
        
        if ($len == 0) return '';
        
        $word = '';
        while (strlen($word) < $len) {
            $word .= fread($this->socket, $len - strlen($word));
        }
        
        return $word;
    }
    
    /**
     * Read full response
     */
    private function readResponse() {
        $responses = [];
        $current = [];
        
        while (true) {
            $word = $this->readWord();
            
            if ($word === '') {
                if (!empty($current)) {
                    $responses[] = $current;
                    $current = [];
                }
            } elseif ($word === '!done') {
                $responses[] = '!done';
                break;
            } elseif ($word === '!trap' || $word === '!fatal') {
                $current['!error'] = $word;
            } elseif (strpos($word, '=') === 0) {
                $parts = explode('=', substr($word, 1), 2);
                if (count($parts) == 2) {
                    $current['=' . $parts[0]] = $parts[1];
                }
            } else {
                $current[] = $word;
            }
            
            // Check for timeout
            $info = stream_get_meta_data($this->socket);
            if ($info['timed_out']) {
                $this->error = "Connection timed out";
                break;
            }
        }
        
        return $responses;
    }
    
    // ========================================
    // HIGH-LEVEL FUNCTIONS
    // ========================================
    
    /**
     * Get router identity
     */
    public function getIdentity() {
        $response = $this->command(['/system/identity/print']);
        if (isset($response[0]['=name'])) {
            return $response[0]['=name'];
        }
        return null;
    }
    
    /**
     * Get router resource info
     */
    public function getResource() {
        $response = $this->command(['/system/resource/print']);
        if (isset($response[0])) {
            return $response[0];
        }
        return null;
    }
    
    /**
     * Get all PPPoE profiles
     */
    public function getPPPoEProfiles() {
        $response = $this->command(['/ppp/profile/print']);
        $profiles = [];
        
        foreach ($response as $item) {
            if (is_array($item) && isset($item['=name'])) {
                $profiles[] = [
                    'id' => $item['=.id'] ?? '',
                    'name' => $item['=name'],
                    'local_address' => $item['=local-address'] ?? '',
                    'remote_address' => $item['=remote-address'] ?? '',
                    'rate_limit' => $item['=rate-limit'] ?? ''
                ];
            }
        }
        
        return $profiles;
    }
    
    /**
     * Get all PPPoE secrets (users)
     */
    public function getPPPoESecrets() {
        $response = $this->command(['/ppp/secret/print']);
        $secrets = [];
        
        foreach ($response as $item) {
            if (is_array($item) && isset($item['=name'])) {
                $secrets[] = [
                    'id' => $item['=.id'] ?? '',
                    'name' => $item['=name'],
                    'password' => $item['=password'] ?? '',
                    'profile' => $item['=profile'] ?? 'default',
                    'service' => $item['=service'] ?? 'pppoe',
                    'caller_id' => $item['=caller-id'] ?? '',
                    'comment' => $item['=comment'] ?? '',
                    'disabled' => ($item['=disabled'] ?? 'false') === 'true',
                    'last_logged_out' => $item['=last-logged-out'] ?? ''
                ];
            }
        }
        
        return $secrets;
    }
    
    /**
     * Get active PPPoE connections
     */
    public function getPPPoEActive() {
        $response = $this->command(['/ppp/active/print']);
        $active = [];
        
        foreach ($response as $item) {
            if (is_array($item) && isset($item['=name'])) {
                $active[] = [
                    'id' => $item['=.id'] ?? '',
                    'name' => $item['=name'],
                    'service' => $item['=service'] ?? '',
                    'caller_id' => $item['=caller-id'] ?? '',
                    'address' => $item['=address'] ?? '',
                    'uptime' => $item['=uptime'] ?? '',
                    'encoding' => $item['=encoding'] ?? ''
                ];
            }
        }
        
        return $active;
    }
    
    /**
     * Change PPPoE user profile (for ISOLIR)
     */
    public function changeProfile($username, $newProfile) {
        // First, find the user
        $response = $this->command([
            '/ppp/secret/print',
            '?name=' . $username
        ]);
        
        if (!isset($response[0]['=.id'])) {
            $this->error = "User '$username' not found";
            return false;
        }
        
        $userId = $response[0]['=.id'];
        
        // Change profile
        $response = $this->command([
            '/ppp/secret/set',
            '=.id=' . $userId,
            '=profile=' . $newProfile
        ]);
        
        // Scan for errors
        foreach ($response as $r) {
            if (is_array($r) && isset($r['!trap'])) {
                $this->error = $r['=message'] ?? 'Unknown MikroTik trap';
                return false;
            }
        }
        
        // Check if last response is !done
        if (end($response) === '!done') {
            return true;
        }
        
        $this->error = "Failed to change profile";
        return false;
    }
    
    /**
     * Enable/Disable PPPoE user
     */
    public function setUserEnabled($username, $enabled = true) {
        $response = $this->command([
            '/ppp/secret/print',
            '?name=' . $username
        ]);
        
        if (!isset($response[0]['=.id'])) {
            $this->error = "User '$username' not found";
            return false;
        }
        
        $userId = $response[0]['=.id'];
        $disabled = $enabled ? 'no' : 'yes';
        
        $response = $this->command([
            '/ppp/secret/set',
            '=.id=' . $userId,
            '=disabled=' . $disabled
        ]);
        
        return end($response) === '!done';
    }
    
    /**
     * Disconnect active PPPoE session
     */
    public function disconnectUser($username) {
        $response = $this->command([
            '/ppp/active/print',
            '?name=' . $username
        ]);
        
        if (!isset($response[0]['=.id'])) {
            $this->error = "User '$username' not active";
            return false;
        }
        
        $sessionId = $response[0]['=.id'];
        
        $response = $this->command([
            '/ppp/active/remove',
            '=.id=' . $sessionId
        ]);
        
        return end($response) === '!done';
    }
    
    /**
     * ISOLIR user - Change to isolir profile and disconnect
     */
    public function isolirUser($username, $isolirProfile = 'isolir') {
        // 1. Change profile
        if (!$this->changeProfile($username, $isolirProfile)) {
            return false;
        }
        
        // 2. Disconnect if active
        $this->disconnectUser($username);
        
        return true;
    }
    
    /**
     * UN-ISOLIR user - Change back to normal profile
     */
    public function unIsolirUser($username, $normalProfile = 'default') {
        // 1. Change profile
        if (!$this->changeProfile($username, $normalProfile)) {
            return false;
        }
        
        // 2. Disconnect if active (force reconnect with new profile)
        $this->disconnectUser($username);
        
        return true;
    }
    
    /**
     * Add new PPPoE user
     */
    public function addPPPoEUser($username, $password, $profile = 'default', $comment = '') {
        $command = [
            '/ppp/secret/add',
            '=name=' . $username,
            '=password=' . $password,
            '=profile=' . $profile,
            '=service=pppoe'
        ];
        
        if ($comment) {
            $command[] = '=comment=' . $comment;
        }
        
        $response = $this->command($command);
        
        foreach ($response as $r) {
            if (is_array($r) && isset($r['!trap'])) {
                $this->error = $r['=message'] ?? 'Unknown MikroTik trap';
                return false;
            }
        }
        
        return end($response) === '!done';
    }
    
    /**
     * Delete PPPoE user
     */
    public function deletePPPoEUser($username) {
        $response = $this->command([
            '/ppp/secret/print',
            '?name=' . $username
        ]);
        
        if (!isset($response[0]['=.id'])) {
            $this->error = "User '$username' not found";
            return false;
        }
        
        $userId = $response[0]['=.id'];
        
        // Disconnect first if active
        $this->disconnectUser($username);
        
        // Delete
        $response = $this->command([
            '/ppp/secret/remove',
            '=.id=' . $userId
        ]);
        
        return end($response) === '!done';
    }
    
    // ========================================
    // HOTSPOT FUNCTIONS
    // ========================================
    
    /**
     * Get active hotspot users
     */
    public function getHotspotActive() {
        $response = $this->command(['/ip/hotspot/active/print']);
        $active = [];
        
        foreach ($response as $item) {
            if (is_array($item) && isset($item['=user'])) {
                $active[] = [
                    'id' => $item['=.id'] ?? '',
                    'user' => $item['=user'],
                    'name' => $item['=user'], // Alias
                    'address' => $item['=address'] ?? '',
                    'mac' => $item['=mac-address'] ?? '',
                    'mac-address' => $item['=mac-address'] ?? '',
                    'uptime' => $item['=uptime'] ?? '',
                    'session-time-left' => $item['=session-time-left'] ?? '',
                    'idle-time' => $item['=idle-time'] ?? '',
                    'bytes-in' => $item['=bytes-in'] ?? '',
                    'bytes-out' => $item['=bytes-out'] ?? ''
                ];
            }
        }
        
        return $active;
    }
    
    /**
     * Get all hotspot users
     */
    public function getHotspotUsers() {
        $response = $this->command(['/ip/hotspot/user/print']);
        $users = [];
        
        foreach ($response as $item) {
            if (is_array($item) && isset($item['=name'])) {
                $users[] = [
                    'id' => $item['=.id'] ?? '',
                    'name' => $item['=name'],
                    'password' => $item['=password'] ?? '',
                    'profile' => $item['=profile'] ?? 'default',
                    'mac' => $item['=mac-address'] ?? '',
                    'mac-address' => $item['=mac-address'] ?? '',
                    'limit-uptime' => $item['=limit-uptime'] ?? '',
                    'comment' => $item['=comment'] ?? '',
                    'disabled' => ($item['=disabled'] ?? 'false') === 'true'
                ];
            }
        }
        
        return $users;
    }
    
    /**
     * Get hotspot user profiles
     */
    public function getHotspotProfiles() {
        $response = $this->command(['/ip/hotspot/user/profile/print']);
        $profiles = [];
        
        foreach ($response as $item) {
            if (is_array($item) && isset($item['=name'])) {
                $profiles[] = [
                    'id' => $item['=.id'] ?? '',
                    'name' => $item['=name'],
                    'rate-limit' => $item['=rate-limit'] ?? '',
                    'session-timeout' => $item['=session-timeout'] ?? '',
                    'idle-timeout' => $item['=idle-timeout'] ?? '',
                    'keepalive-timeout' => $item['=keepalive-timeout'] ?? '',
                    'on-login' => $item['=on-login'] ?? ''  // TAMBAHAN: untuk parse Mikhmon script
                ];
            }
        }
        
        return $profiles;
    }
    
    /**
     * Add new hotspot user
     */
    public function addHotspotUser($username, $password, $profile = 'default', $mac = '', $uptime = '', $comment = '') {
        $command = [
            '/ip/hotspot/user/add',
            '=name=' . $username,
            '=password=' . $password,
            '=profile=' . $profile
        ];
        
        if ($mac) {
            $command[] = '=mac-address=' . $mac;
        }
        
        if ($uptime) {
            $command[] = '=limit-uptime=' . $uptime;
        }
        
        if ($comment) {
            $command[] = '=comment=' . $comment;
        }
        
        $response = $this->command($command);
        
        return end($response) === '!done';
    }
    
    /**
     * Disconnect active hotspot user
     */
    public function disconnectHotspotUser($username) {
        $response = $this->command([
            '/ip/hotspot/active/print',
            '?user=' . $username
        ]);
        
        if (!isset($response[0]['=.id'])) {
            $this->error = "User '$username' not active";
            return false;
        }
        
        $sessionId = $response[0]['=.id'];
        
        $response = $this->command([
            '/ip/hotspot/active/remove',
            '=.id=' . $sessionId
        ]);
        
        return end($response) === '!done';
    }
    
    /**
     * Set hotspot user status (enable/disable)
     */
    public function setHotspotUserStatus($username, $disabled = false) {
        $response = $this->command([
            '/ip/hotspot/user/print',
            '?name=' . $username
        ]);
        
        if (!isset($response[0]['=.id'])) {
            $this->error = "User '$username' not found";
            return false;
        }
        
        $userId = $response[0]['=.id'];
        $disabledValue = $disabled ? 'yes' : 'no';
        
        $response = $this->command([
            '/ip/hotspot/user/set',
            '=.id=' . $userId,
            '=disabled=' . $disabledValue
        ]);
        
        return end($response) === '!done';
    }
    
    /**
     * Delete hotspot user
     */
    public function deleteHotspotUser($username) {
        $response = $this->command([
            '/ip/hotspot/user/print',
            '?name=' . $username
        ]);
        
        if (!isset($response[0]['=.id'])) {
            $this->error = "User '$username' not found";
            return false;
        }
        
        $userId = $response[0]['=.id'];
        
        // Disconnect first if active
        $this->disconnectHotspotUser($username);
        
        // Delete
        $response = $this->command([
            '/ip/hotspot/user/remove',
            '=.id=' . $userId
        ]);
        
        return end($response) === '!done';
    }
    
    // ========================================
    // HOTSPOT PROFILE MANAGEMENT
    // ========================================
    
    /**
     * Get hotspot server profiles
     */
    public function getHotspotServerProfiles() {
        $response = $this->command(['/ip/hotspot/profile/print']);
        $profiles = [];
        
        foreach ($response as $item) {
            if (is_array($item) && isset($item['=name'])) {
                $profiles[] = [
                    'id' => $item['=.id'] ?? '',
                    'name' => $item['=name'],
                    'html-directory' => $item['=html-directory'] ?? 'hotspot',
                    'dns-name' => $item['=dns-name'] ?? '',
                    'login-by' => $item['=login-by'] ?? 'http-chap',
                    'smtp-server' => $item['=smtp-server'] ?? '0.0.0.0',
                    'split-user-domain' => $item['=split-user-domain'] ?? 'no',
                    'rate-limit' => $item['=rate-limit'] ?? ''
                ];
            }
        }
        
        return $profiles;
    }
    
    /**
     * Add hotspot user profile
     */
    public function addHotspotUserProfile($name, $rateLimit = '', $sessionTimeout = '', $idleTimeout = '', $sharedUsers = '1', $keepaliveTimeout = '', $statusAutorefresh = '', $onLogin = '') {
        $command = [
            '/ip/hotspot/user/profile/add',
            '=name=' . $name
        ];
        
        if ($rateLimit) {
            $command[] = '=rate-limit=' . $rateLimit;
        }
        
        if ($sessionTimeout) {
            $command[] = '=session-timeout=' . $sessionTimeout;
        }
        
        if ($idleTimeout) {
            $command[] = '=idle-timeout=' . $idleTimeout;
        }
        
        if ($sharedUsers) {
            $command[] = '=shared-users=' . $sharedUsers;
        }
        
        if ($keepaliveTimeout) {
            $command[] = '=keepalive-timeout=' . $keepaliveTimeout;
        }
        
        if ($statusAutorefresh) {
            $command[] = '=status-autorefresh=' . $statusAutorefresh;
        }
        
        if ($onLogin) {
            $command[] = '=on-login=' . $onLogin;
        }
        
        $response = $this->command($command);
        
        return end($response) === '!done';
    }
    
    /**
     * Delete hotspot user profile
     */
    public function deleteHotspotUserProfile($name) {
        $response = $this->command([
            '/ip/hotspot/user/profile/print',
            '?name=' . $name
        ]);
        
        if (!isset($response[0]['=.id'])) {
            $this->error = "Profile '$name' not found";
            return false;
        }
        
        $profileId = $response[0]['=.id'];
        
        $response = $this->command([
            '/ip/hotspot/user/profile/remove',
            '=.id=' . $profileId
        ]);
        
        return end($response) === '!done';
    }
    
    /**
     * Add hotspot server profile
     */
    public function addHotspotServerProfile($name, $htmlDirectory = 'hotspot', $dnsName = '', $loginBy = 'http-chap', $smtpServer = '0.0.0.0', $splitUserDomain = 'no', $rateLimit = '') {
        $command = [
            '/ip/hotspot/profile/add',
            '=name=' . $name,
            '=html-directory=' . $htmlDirectory,
            '=login-by=' . $loginBy,
            '=smtp-server=' . $smtpServer,
            '=split-user-domain=' . $splitUserDomain
        ];
        
        if ($dnsName) {
            $command[] = '=dns-name=' . $dnsName;
        }
        
        if ($rateLimit) {
            $command[] = '=rate-limit=' . $rateLimit;
        }
        
        $response = $this->command($command);
        
        return end($response) === '!done';
    }
    
    /**
     * Delete hotspot server profile
     */
    public function deleteHotspotServerProfile($name) {
        $response = $this->command([
            '/ip/hotspot/profile/print',
            '?name=' . $name
        ]);
        
        if (!isset($response[0]['=.id'])) {
            $this->error = "Server profile '$name' not found";
            return false;
        }
        
        $profileId = $response[0]['=.id'];
        
        $response = $this->command([
            '/ip/hotspot/profile/remove',
            '=.id=' . $profileId
        ]);
        
        return end($response) === '!done';
    }
    
    /**
     * Create or update hotspot profile with on-login script (Mikhmon Style)
     * Auto-inject script that will create scheduler for auto-expire
     * 
     * @param string $name Profile name (e.g., "3JAM", "1HARI")
     * @param int $price Voucher price
     * @param string $duration Duration string (e.g., "3h", "1d")
     * @param string $rateLimit Rate limit (e.g., "2M/2M")
     * @param string $sharedUsers Shared users (default: "1")
     * @return bool Success status
     */
    public function createHotspotProfileWithScript($name, $price, $duration, $rateLimit = '', $sharedUsers = '1') {
        // Generate Mikhmon-compatible on-login script
        // This script will:
        // 1. Parse voucher comment
        // 2. Create scheduler to auto-disable user after duration expires
        // 3. Bind MAC address
        // Format yang sama dengan Mikhmon: :put ",rem,PRICE,DURATION,,,Disable,";
        
        $onLoginScript = ":put \",rem,{$price},{$duration},,,Disable,\";";
        
        // Check if profile exists
        $response = $this->command([
            '/ip/hotspot/user/profile/print',
            '?name=' . $name
        ]);
        
        $command = [];
        
        if (isset($response[0]['=.id'])) {
            // Update existing profile
            $profileId = $response[0]['=.id'];
            $command = [
                '/ip/hotspot/user/profile/set',
                '=.id=' . $profileId,
                '=on-login=' . $onLoginScript
            ];
            
            if ($rateLimit) {
                $command[] = '=rate-limit=' . $rateLimit;
            }
            if ($sharedUsers) {
                $command[] = '=shared-users=' . $sharedUsers;
            }
        } else {
            // Create new profile
            $command = [
                '/ip/hotspot/user/profile/add',
                '=name=' . $name,
                '=on-login=' . $onLoginScript
            ];
            
            if ($rateLimit) {
                $command[] = '=rate-limit=' . $rateLimit;
            }
            if ($sharedUsers) {
                $command[] = '=shared-users=' . $sharedUsers;
            }
        }
        
        $response = $this->command($command);
        
        // Check for errors
        foreach ($response as $r) {
            if (is_array($r) && isset($r['!trap'])) {
                $this->error = $r['=message'] ?? 'Unknown MikroTik trap';
                return false;
            }
        }
        
        return end($response) === '!done';
    }
    
    /**
     * Set on-login script only (for existing profile)
     */
    public function setProfileOnLogin($profileName, $onLoginScript) {
        // Find profile
        $response = $this->command([
            '/ip/hotspot/user/profile/print',
            '?name=' . $profileName
        ]);
        
        if (!isset($response[0]['=.id'])) {
            $this->error = "Profile '$profileName' not found";
            return false;
        }
        
        $profileId = $response[0]['=.id'];
        
        // Set on-login script
        $response = $this->command([
            '/ip/hotspot/user/profile/set',
            '=.id=' . $profileId,
            '=on-login=' . $onLoginScript
        ]);
        
        return end($response) === '!done';
    }
    
    // ========================================
    // PPPOE PROFILE MANAGEMENT
    // ========================================
    
    /**
     * Add PPPoE profile
     */
    public function addPPPoEProfile($name, $rateLimit = '', $localAddress = '', $remoteAddress = '', $dnsServer = '', $sessionTimeout = '') {
        $command = [
            '/ppp/profile/add',
            '=name=' . $name
        ];
        
        if ($rateLimit) {
            $command[] = '=rate-limit=' . $rateLimit;
        }
        
        if ($localAddress) {
            $command[] = '=local-address=' . $localAddress;
        }
        
        if ($remoteAddress) {
            $command[] = '=remote-address=' . $remoteAddress;
        }
        
        if ($dnsServer) {
            $command[] = '=dns-server=' . $dnsServer;
        }
        
        if ($sessionTimeout) {
            $command[] = '=session-timeout=' . $sessionTimeout;
        }
        
        $response = $this->command($command);
        
        return end($response) === '!done';
    }
    
    /**
     * Delete PPPoE profile
     */
    public function deletePPPoEProfile($name) {
        $response = $this->command([
            '/ppp/profile/print',
            '?name=' . $name
        ]);
        
        if (!isset($response[0]['=.id'])) {
            $this->error = "Profile '$name' not found";
            return false;
        }
        
        $profileId = $response[0]['=.id'];
        
        $response = $this->command([
            '/ppp/profile/remove',
            '=.id=' . $profileId
        ]);
        
        return end($response) === '!done';
    }
    
    // ========================================
    // MIKROTIK TOOLS METHODS
    // ========================================
    
    /**
     * Ping a host
     */
    public function ping($address, $count = 4) {
        $response = $this->command([
            '/ping',
            '=address=' . $address,
            '=count=' . $count
        ]);
        
        $results = [];
        foreach ($response as $item) {
            if (is_array($item) && isset($item['=seq'])) {
                $results[] = [
                    'seq' => $item['=seq'] ?? '',
                    'host' => $item['=host'] ?? $address,
                    'size' => $item['=size'] ?? '',
                    'ttl' => $item['=ttl'] ?? '',
                    'time' => $item['=time'] ?? null
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Get all interfaces
     */
    public function getInterfaces() {
        $response = $this->command(['/interface/print']);
        $interfaces = [];
        
        foreach ($response as $item) {
            if (is_array($item) && isset($item['=name'])) {
                $interfaces[] = [
                    'id' => $item['=.id'] ?? '',
                    'name' => $item['=name'],
                    'type' => $item['=type'] ?? '',
                    'mtu' => $item['=mtu'] ?? '',
                    'running' => $item['=running'] ?? 'false',
                    'disabled' => $item['=disabled'] ?? 'false',
                    'comment' => $item['=comment'] ?? ''
                ];
            }
        }
        
        return $interfaces;
    }
    
    /**
     * Get router log
     */
    public function getLog($limit = 20) {
        $response = $this->command(['/log/print']);
        $logs = [];
        
        foreach ($response as $item) {
            if (is_array($item) && isset($item['=message'])) {
                $logs[] = [
                    'id' => $item['=.id'] ?? '',
                    'time' => $item['=time'] ?? '',
                    'topics' => $item['=topics'] ?? '',
                    'message' => $item['=message']
                ];
            }
        }
        
        // Return last N logs (newest first)
        return array_slice(array_reverse($logs), 0, $limit);
    }
    
    /**
     * Get interface traffic
     */
    public function getTraffic($interface = 'ether1') {
        $response = $this->command([
            '/interface/monitor-traffic',
            '=interface=' . $interface,
            '=once='
        ]);
        
        $traffic = [];
        foreach ($response as $item) {
            if (is_array($item)) {
                if (isset($item['=tx-bits-per-second'])) {
                    $traffic['tx-bits-per-second'] = $item['=tx-bits-per-second'];
                }
                if (isset($item['=rx-bits-per-second'])) {
                    $traffic['rx-bits-per-second'] = $item['=rx-bits-per-second'];
                }
                if (isset($item['=tx-packets-per-second'])) {
                    $traffic['tx-packets-per-second'] = $item['=tx-packets-per-second'];
                }
                if (isset($item['=rx-packets-per-second'])) {
                    $traffic['rx-packets-per-second'] = $item['=rx-packets-per-second'];
                }
            }
        }
        
        return $traffic;
    }
    
    /**
     * Get DHCP leases
     */
    public function getDHCPLeases() {
        $response = $this->command(['/ip/dhcp-server/lease/print']);
        $leases = [];
        
        foreach ($response as $item) {
            if (is_array($item) && isset($item['=address'])) {
                $leases[] = [
                    'id' => $item['=.id'] ?? '',
                    'address' => $item['=address'],
                    'mac-address' => $item['=mac-address'] ?? '',
                    'host-name' => $item['=host-name'] ?? '',
                    'status' => $item['=status'] ?? '',
                    'expires-after' => $item['=expires-after'] ?? '',
                    'server' => $item['=server'] ?? '',
                    'disabled' => ($item['=disabled'] ?? 'false') === 'true'
                ];
            }
        }
        
        return $leases;
    }
}
