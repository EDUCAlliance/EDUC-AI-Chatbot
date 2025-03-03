ThinkLab-TalkBot
This is the code for our chatbot, which will then be used as a bot for Nextcloud Talk on the EDUC Virtual Campus.

This is a project within the EDUC ThinkLab


install guide
First of all, you have to install a bot. The term “register” would almost be more appropriate here, as you simply tell Nextcloud to send a webhook to a specific URL after each message (as soon as this bot is activated in the chat) - It is also important to remember the secret token, because the script must encrypt the message with this token in order to be able to respond back

cd /path/to/nextcloud-occ-file && sudo -u www-data php occ talk:bot:install -f webhook,response "Name of the Bot" "XXXX-Secrect-Token-XXXX" "https://Domain-of-Nextcloud.de/script-to-handle-webhook.php"
If you want to check, if the "installation" of the bot is correct, you can see an list of all bots with this command (you should now also be able to activate the bot in the Conversation settings of an Nextcloud Talk Chat)

sudo  -u www-data php occ talk:bot:list
More OCC Commands can you find here: https://nextcloud-talk.readthedocs.io/en/latest/occ/#talkbotinstall




The Talkbot, has this new features:

1. Connection to the Chat-AI API from GWDG
2. Everything that needs to be customized is now separated from the PHP script (The .env File and the llm_config File)
3. The bot now only responds when it is pinged with @name-of-bot (you can define the exact name under which the bot should be mentioned in the .env file)
