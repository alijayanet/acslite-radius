// Modul logger untuk aplikasi
const fs = require('fs');
const path = require('path');

class Logger {
    constructor() {
        this.logDir = path.join(__dirname, '../logs');
        this.ensureLogDirectory();
    }

    ensureLogDirectory() {
        if (!fs.existsSync(this.logDir)) {
            fs.mkdirSync(this.logDir, { recursive: true });
        }
    }

    getTimestamp() {
        return new Date().toISOString();
    }

    // Safe serializer to avoid circular references and huge dumps
    safeSerialize(data) {
        const seen = new WeakSet();

        // Handle Error objects cleanly
        const serializeError = (err) => ({
            name: err.name,
            message: err.message,
            stack: err.stack,
        });

        // Handle Axios-style errors
        const serializeAxiosError = (err) => {
            const out = {
                isAxiosError: true,
                name: err.name,
                message: err.message,
                code: err.code,
            };
            if (err.config) {
                out.config = {
                    method: err.config.method,
                    url: err.config.url,
                    headers: err.config.headers && typeof err.config.headers === 'object' ? { ...err.config.headers } : undefined,
                };
                // Avoid dumping huge request data
                if (err.config.data) {
                    const dataStr = typeof err.config.data === 'string' ? err.config.data : (() => { try { return JSON.stringify(err.config.data); } catch { return '[unserializable request data]'; } })();
                    out.config.data = dataStr.length > 1000 ? dataStr.slice(0, 1000) + '...<truncated>' : dataStr;
                }
            }
            if (err.response) {
                const respData = (() => {
                    try { return typeof err.response.data === 'string' ? err.response.data : JSON.stringify(err.response.data); } catch { return '[unserializable response data]'; }
                })();
                out.response = {
                    status: err.response.status,
                    statusText: err.response.statusText,
                    headers: err.response.headers,
                    data: respData && respData.length > 2000 ? respData.slice(0, 2000) + '...<truncated>' : respData,
                };
            }
            return out;
        };

        const replacer = (key, value) => {
            if (typeof value === 'object' && value !== null) {
                if (seen.has(value)) return '[Circular]';
                seen.add(value);
            }
            return value;
        };

        try {
            if (data instanceof Error) {
                return JSON.stringify(serializeError(data), null, 2);
            }
            // Detect Axios error shape
            if (data && typeof data === 'object' && (data.isAxiosError || (data.response && data.config))) {
                return JSON.stringify(serializeAxiosError(data), null, 2);
            }
            const json = JSON.stringify(data, replacer, 2);
            // Truncate extremely long strings
            return json && json.length > 5000 ? json.slice(0, 5000) + '...<truncated>' : json;
        } catch (e) {
            // As a last resort, toString
            try { return String(data); } catch { return '[Unserializable data]'; }
        }
    }

    formatMessage(level, message, data = null) {
        const timestamp = this.getTimestamp();
        let logMessage = `[${timestamp}] [${level.toUpperCase()}] ${message}`;
        
        if (data) {
            if (typeof data === 'object') {
                logMessage += `\n${this.safeSerialize(data)}`;
            } else {
                logMessage += ` - ${data}`;
            }
        }
        
        return logMessage;
    }

    writeToFile(level, message, data = null) {
        const logMessage = this.formatMessage(level, message, data);
        const logFile = path.join(this.logDir, `${level}.log`);
        
        try {
            fs.appendFileSync(logFile, logMessage + '\n');
        } catch (error) {
            console.error('Error writing to log file:', error);
        }
    }

    info(message, data = null) {
        const logMessage = this.formatMessage('info', message, data);
        console.log(logMessage);
        this.writeToFile('info', message, data);
    }

    warn(message, data = null) {
        const logMessage = this.formatMessage('warn', message, data);
        console.warn(logMessage);
        this.writeToFile('warn', message, data);
    }

    error(message, data = null) {
        const logMessage = this.formatMessage('error', message, data);
        console.error(logMessage);
        this.writeToFile('error', message, data);
    }

    debug(message, data = null) {
        if (process.env.NODE_ENV === 'development') {
            const logMessage = this.formatMessage('debug', message, data);
            console.log(logMessage);
            this.writeToFile('debug', message, data);
        }
    }
}

// Create singleton instance
const logger = new Logger();

module.exports = logger;
