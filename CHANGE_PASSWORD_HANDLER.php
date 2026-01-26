<?php
/**
 * Change Password Handler for billing_api.php
 * Add this case to the switch statement in billing_api.php after 'delete_customer' case
 */

/*
case 'change_password':
    $customerId = $input['customer_id'] ?? '';
    $oldPassword = $input['old_password'] ?? '';
    $newPassword = $input['new_password'] ?? '';
    
    if (!$customerId || !$oldPassword || !$newPassword) {
        jsonResponse(['success' => false, 'error' => 'All fields required'], 400);
    }
    
    if (strlen($newPassword) < 4) {
        jsonResponse(['success' => false, 'error' => 'Password minimal 4 karakter'], 400);
    }
    
    // Get customer
    $stmt = $pdo->prepare("SELECT id, portal_password FROM customers WHERE id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();
    
    if (!$customer) {
        jsonResponse(['success' => false, 'error' => 'Customer not found'], 404);
    }
    
    // Verify old password
    if (!password_verify($oldPassword, $customer['portal_password'])) {
        jsonResponse(['success' => false, 'error' => 'Password lama salah'], 401);
    }
    
    // Update password
    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("UPDATE customers SET portal_password = ? WHERE id = ?");
    $stmt->execute([$hashedPassword, $customerId]);
    
    jsonResponse(['success' => true, 'message' => 'Password berhasil diubah']);
    break;
*/
