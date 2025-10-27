#!/bin/bash
# Anonymous Widget Data Cleanup Cron Job
# 
# This script should be run daily to clean up old anonymous widget data
# Add to crontab: 0 2 * * * /path/to/synaplan.com/web/cron_cleanup_anonymous.sh
#
# The script runs at 2 AM daily to minimize impact on users

cd /wwwroot/synaplan.com/web/
php cleanup_anonymous_data.php >> logs/cron_cleanup.log 2>&1 