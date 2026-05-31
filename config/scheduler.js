const cron = require('node-cron');
const billingManager = require('./billing');
const logger = require('./logger');

class InvoiceScheduler {
    constructor() {
        this.initScheduler();
    }

    initScheduler() {
        // Schedule monthly invoice generation on 1st of every month at 08:00
        cron.schedule('0 8 1 * *', async () => {
            try {
                logger.info('Starting automatic monthly invoice generation (08:00)...');
                await this.generateMonthlyInvoices();
                logger.info('Automatic monthly invoice generation completed');
            } catch (error) {
                logger.error('Error in automatic monthly invoice generation:', error);
            }
        }, {
            scheduled: true,
            timezone: "Asia/Jakarta"
        });

        logger.info('Invoice scheduler initialized - will run on 1st of every month at 08:00');
        
        // Schedule daily due date reminders at 09:00
        cron.schedule('0 9 * * *', async () => {
            try {
                logger.info('Starting daily due date reminders...');
                await this.sendDueDateReminders();
                logger.info('Daily due date reminders completed');
            } catch (error) {
                logger.error('Error in daily due date reminders:', error);
            }
        }, {
            scheduled: true,
            timezone: "Asia/Jakarta"
        });
        
        logger.info('Due date reminder scheduler initialized - will run daily at 09:00');

        // Schedule daily service suspension check at 10:00
        cron.schedule('0 10 * * *', async () => {
            try {
                logger.info('Starting daily service suspension check...');
                const serviceSuspension = require('./serviceSuspension');
                await serviceSuspension.checkAndSuspendOverdueCustomers();
                logger.info('Daily service suspension check completed');
            } catch (error) {
                logger.error('Error in daily service suspension check:', error);
            }
        }, {
            scheduled: true,
            timezone: "Asia/Jakarta"
        });

        // Schedule daily service restoration check at 11:00
        cron.schedule('0 11 * * *', async () => {
            try {
                logger.info('Starting daily service restoration check...');
                const serviceSuspension = require('./serviceSuspension');
                await serviceSuspension.checkAndRestorePaidCustomers();
                logger.info('Daily service restoration check completed');
            } catch (error) {
                logger.error('Error in daily service restoration check:', error);
            }
        }, {
            scheduled: true,
            timezone: "Asia/Jakarta"
        });

        logger.info('Service suspension/restoration scheduler initialized - will run daily at 10:00 and 11:00');
        

    }

    async sendDueDateReminders() {
        try {
            const whatsappNotifications = require('./whatsapp-notifications');
            const invoices = await billingManager.getInvoices();
            const today = new Date();
            
            // Filter invoices that are due in the next 3 days
            const upcomingInvoices = invoices.filter(invoice => {
                if (invoice.status !== 'unpaid') return false;
                
                const dueDate = new Date(invoice.due_date);
                const daysUntilDue = Math.ceil((dueDate - today) / (1000 * 60 * 60 * 24));
                
                return daysUntilDue >= 0 && daysUntilDue <= 3;
            });
            
            logger.info(`Found ${upcomingInvoices.length} invoices due in the next 3 days`);
            
            for (const invoice of upcomingInvoices) {
                try {
                    await whatsappNotifications.sendDueDateReminder(invoice.id);
                    logger.info(`Due date reminder sent for invoice ${invoice.invoice_number}`);
                } catch (error) {
                    logger.error(`Error sending due date reminder for invoice ${invoice.invoice_number}:`, error);
                }
            }
        } catch (error) {
            logger.error('Error in sendDueDateReminders:', error);
            throw error;
        }
    }

    async generateMonthlyInvoices() {
        try {
            // Get all active customers
            const customers = await billingManager.getCustomers();
            const activeCustomers = customers.filter(customer => 
                customer.status === 'active' && customer.package_id
            );

            logger.info(`Found ${activeCustomers.length} active customers for invoice generation`);

            for (const customer of activeCustomers) {
                try {
                                            // Get customer's package
                        const packageData = await billingManager.getPackageById(customer.package_id);
                        if (!packageData) {
                            logger.warn(`Package not found for customer ${customer.username}`);
                            continue;
                        }

                    // Check if invoice already exists for this month
                    const currentDate = new Date();
                    const startOfMonth = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);
                    const endOfMonth = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 0);

                    const existingInvoices = await billingManager.getInvoicesByCustomerAndDateRange(
                        customer.username,
                        startOfMonth,
                        endOfMonth
                    );

                    if (existingInvoices.length > 0) {
                        logger.info(`Invoice already exists for customer ${customer.username} this month`);
                        continue;
                    }

                    // Set due date based on customer's billing_day (1-28), capped to month's last day
                    const billingDay = (() => {
                        const v = parseInt(customer.billing_day, 10);
                        if (Number.isFinite(v)) return Math.min(Math.max(v, 1), 28);
                        return 15;
                    })();
                    const lastDayOfMonth = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 0).getDate();
                    const targetDay = Math.min(billingDay, lastDayOfMonth);
                    const dueDate = new Date(currentDate.getFullYear(), currentDate.getMonth(), targetDay);

                                            // Create invoice data
                        const invoiceData = {
                            customer_id: customer.id,
                            package_id: customer.package_id,
                            amount: packageData.price,
                            due_date: dueDate.toISOString().split('T')[0],
                            notes: `Tagihan bulanan ${currentDate.toLocaleDateString('id-ID', { month: 'long', year: 'numeric' })}`
                        };

                    // Create the invoice
                    const newInvoice = await billingManager.createInvoice(invoiceData);
                    logger.info(`Created invoice ${newInvoice.invoice_number} for customer ${customer.username}`);

                } catch (error) {
                    logger.error(`Error creating invoice for customer ${customer.username}:`, error);
                }
            }

        } catch (error) {
            logger.error('Error in generateMonthlyInvoices:', error);
            throw error;
        }
    }

    // Manual trigger for testing
    async triggerMonthlyInvoices() {
        try {
            logger.info('Triggering monthly invoice generation manually...');
            await this.generateMonthlyInvoices();
            logger.info('Manual monthly invoice generation completed');
            return { success: true, message: 'Monthly invoices generated successfully' };
        } catch (error) {
            logger.error('Error in manual monthly invoice generation:', error);
            throw error;
        }
    }


}

module.exports = new InvoiceScheduler(); 