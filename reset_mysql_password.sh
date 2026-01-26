#!/bin/bash

# Script untuk reset MySQL root password ke 'secret123'
# Sesuai dengan konfigurasi di .env

echo "========================================="
echo "Reset MySQL Root Password"
echo "========================================="
echo ""

# Password yang diinginkan (sesuai .env)
NEW_PASSWORD="secret123"

echo "[INFO] Resetting MySQL root password to: $NEW_PASSWORD"
echo ""

# Reset password
sudo mysql -u root <<EOF
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$NEW_PASSWORD';
FLUSH PRIVILEGES;
EOF

if [ $? -eq 0 ]; then
    echo ""
    echo "[SUCCESS] Password berhasil direset!"
    echo ""
    
    # Test password
    echo "[INFO] Testing new password..."
    mysql -u root -p$NEW_PASSWORD -e "SELECT 'Password OK!' as status;" 2>/dev/null
    
    if [ $? -eq 0 ]; then
        echo ""
        echo "✅ [SUCCESS] MySQL root password sekarang: $NEW_PASSWORD"
        echo ""
        echo "Sekarang Anda bisa jalankan:"
        echo "  sudo bash install.sh"
        echo ""
    else
        echo ""
        echo "⚠️ [WARNING] Password direset tapi test gagal."
        echo "Coba login manual: mysql -u root -p$NEW_PASSWORD"
        echo ""
    fi
else
    echo ""
    echo "❌ [ERROR] Gagal reset password."
    echo ""
    echo "Coba manual:"
    echo "  sudo mysql -u root"
    echo "  ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$NEW_PASSWORD';"
    echo "  FLUSH PRIVILEGES;"
    echo "  EXIT;"
    echo ""
fi
