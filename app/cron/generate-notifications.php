<?php
// cron/generate-notifications.php
// Run this script every hour via cron job
// Example cron: 0 * * * * php /path/to/cron/generate-notifications.php

require_once __DIR__ . '/../config/autoload.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../models/Company.php';

// Disable time limit for cron jobs
set_time_limit(0);

// Log start
error_log("Cron job started: Generating notifications");

try {
    $db = Database::getInstance();
    $companyModel = new Company();

    // Get all active companies
    $companies = $companyModel->all(['id', 'company_name'], 'is_active = 1');

    $totalNotifications = 0;
    $totalCompanies = 0;

    foreach ($companies as $company) {
        echo "Processing company: {$company['company_name']} (ID: {$company['id']})\n";
        error_log("Processing company: {$company['company_name']} (ID: {$company['id']})");

        $notificationModel = new Notification($company['id']);

        // Generate notifications for this company
        $notifications = $notificationModel->generateSystemNotifications();
        $createdCount = 0;

        foreach ($notifications as $notif) {
            // Check if similar notification already exists
            $exists = $notificationModel->checkExistingNotification(
                $notif['user_id'],
                $notif['type'],
                $notif['data'] ?? null,
                24 // Check within last 24 hours
            );

            if (!$exists) {
                $notificationModel->createNotification(
                    $notif['user_id'],
                    $notif['type'],
                    $notif['title'],
                    $notif['message'],
                    $notif['link'] ?? null,
                    $notif['data'] ?? null
                );
                $createdCount++;
            }
        }

        $totalNotifications += $createdCount;
        $totalCompanies++;
        echo "Generated {$createdCount} notifications for company {$company['company_name']}\n";
    }

    echo "========================================\n";
    echo "Total companies processed: {$totalCompanies}\n";
    echo "Total notifications created: {$totalNotifications}\n";
    echo "========================================\n";

    error_log("Cron job completed: Generated {$totalNotifications} notifications for {$totalCompanies} companies");

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    error_log("Cron notification error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
}