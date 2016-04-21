Magento
=======

This is the Adyen Payment plugin for Magento. The plugin supports the Magento Community and Enterprise edition.

We commit all our new features directly into our GitHub repository.
But you can also request or suggest new features or code changes yourself!

<h2>Setup Module</h2>
* <a target="_blank" href="http://vimeo.com/94005128">Click here to see the video how to setup your Adyen Magento module and the Adyen backoffice</a>
* <a target="_blank" href="https://www.adyen.com/dam/jcr:80ea0213-02cd-43aa-8136-459a471d2a0d/MagentoQuickIntegrationManual.pdf">Click here to download the Magento Quick Integration Guide how to setup the basics for the Adyen Magento module and the Adyen backoffice</a>
* <a target="_blank" href="https://docs.adyen.com/display/TD/Magento+Integration">For a more advanced manual click here</a>
* <a target="_blank" href="https://vimeo.com/128983014">Click here to see the Point-of-Sale demo of the Adyen Payment module</a>
* <a target="_blank" href="https://vimeo.com/135459940">Click here to see how to configure the configuration of the Point-of-Sale</a>

<h2>Setup Cron</h2>
For Adyen Payment Plug-in Version >= 2.5.0 it is needed to enable the Magento cron. Make sure that this is running every minute.
We are using a cronjob to process the notifications. The cronjob will be executed every minute. It only executes the notifications that have been received at least 5 minutes ago. We have built in this 5 minutes so we are sure Magento has created the order and all save after events are executed.
A handy tool to get inside into your cronjobs is AOE scheduler. You can download this tool through <a href="http://www.magentocommerce.com/magento-connect/aoe-scheduler.html" target="_blank">Magento Connect</a> or <a target="_blank" href="https://github.com/AOEpeople/Aoe_Scheduler/releases">GitHub</a>

<h2>Support</h2>
You can create issues on our Magento Repository or if you have some specific problems for your account you can contact <a href="mailto:magento@adyen.com">magento@adyen.com</a>  as well.

<h2>Subscription</h2>
If you want to offer subscription to your shoppers see here our subscription module <a target="_blank" href="https://github.com/Adyen/adyen-magento-subscription">here</a>

<h2>Offical Releases</h2>
[Click here to see and download the official releases of the Adyen Payment module](https://github.com/Adyen/magento/releases)

<h2>Update script for 2.4.0 for Adyen OneClick users</h2>
In the new version of the module 2.4.0 the Recurring References are saved into the Billing Agreements of Magento.
If you already running the Adyen plugin that has version 2.3.1 or lower you need to import the already saved card data into your Magento store if you want to show OneClick to your current shoppers.

To import the current saved cards into billing agreements of Magento you need to manually execute the script by following these steps:
1. Go into your terminal
2. Go to the Magento home directory
3. Go inside the folder shell
4. Now execute the script by: php adyen.php -action loadBillingAgreements

All new saved cards will be automatically saved into billingAgreement of magento. Make sure you have turned on RECURRING_CONTRACT notification on your merchantAccount. If you want to add this or if you are not sure, just send us an email us magento@adyen.com.