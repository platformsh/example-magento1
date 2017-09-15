<?php

class Platformsh
{
    const MAGIC_ROUTE = '{default}';

    const PREFIX_SECURE = 'https://';
    const PREFIX_UNSECURE = 'http://';

    // This is the Magento root
    protected $webRoot = 'web';
    // $debugMode provides a wealth of helpful logging that prints to std out
    // during the build phase and the deploy log during deploy.  None of this
    // is accessible to the outside world, but of course feel free to 
    // set this for FALSE once you get your project set up.
    protected $debugMode = TRUE;

    protected $platformReadWriteDirs = ['var', 'app/etc', 'media'];

    protected $urls = ['unsecure' => [], 'secure' => []];

    protected $defaultCurrency = 'USD';

    protected $dbHost;
    protected $dbName;
    protected $dbUser;
    protected $dbPassword;

    protected $adminUsername;
    protected $adminFirstname;
    protected $adminLastname;
    protected $adminEmail;
    protected $adminPassword;

    protected $redisHost;
    protected $redisScheme;
    protected $redisPort;

    protected $solrHost;
    protected $solrPath;
    protected $solrPort;
    protected $solrScheme;

    protected $lastOutput = array();
    protected $lastStatus = null;

    /**
     * Parse Platform.sh routes to more readable format.
     */
    public function initRoutes()
    {
        $this->log("Initializing routes.");

        $routes = $this->getRoutes();

        foreach($routes as $key => $val) {
            if ($val["type"] !== "upstream") {
                continue;
            }

            $urlParts = parse_url($val['original_url']);
            $originalUrl = str_replace(self::MAGIC_ROUTE, '', $urlParts['host']);

            if(strpos($key, self::PREFIX_UNSECURE) === 0) {
                $this->urls['unsecure'][$originalUrl] = $key;
                continue;
            }

            if(strpos($key, self::PREFIX_SECURE) === 0) {
                $this->urls['secure'][$originalUrl] = $key;
                continue;
            }
        }

        if (!count($this->urls['secure'])) {
            $this->urls['secure'] = $this->urls['unsecure'];
        }

        $this->log(sprintf("Routes: %s", var_export($this->urls, true)));
    }

    /**
     * Build application: clear temp directory and move writable directories content to temp.
     */
    public function build()
    {
        $this->log("Start build.");

        $this->clearTemp();

        $this->log("Copying read/write directories to temp directory.");

        foreach ($this->platformReadWriteDirs as $dir) {
            $this->execute(sprintf('mkdir -p init/%s', $dir));
            $this->execute(sprintf('/bin/bash -c "shopt -s dotglob; cp -R %s/%s/* init/%s/"', $this->webRoot, $dir, $dir));
            $this->execute(sprintf('rm -rf %s/%s', $this->webRoot, $dir));
            $this->execute(sprintf('mkdir %s/%s', $this->webRoot, $dir));
        }
    }

    /**
     * Deploy application: copy writable directories back, install or update Magento data.
     */
    public function deploy()
    {
        $this->log("Start deploy.");

        $this->_init();

        $this->log("Copying read/write directories back.");

        foreach ($this->platformReadWriteDirs as $dir) {
            $this->execute(sprintf('/bin/bash -c "shopt -s dotglob; cp -R init/%s/* %s/%s/ || true"', $dir, $this->webRoot, $dir));
            $this->log(sprintf('Copied directory: %s', $dir));
        }

        if (!file_exists("{$this->webRoot}/app/etc/local.xml")) {
            $this->installMagento();
        } else {
            $this->updateMagento();
        }
    }

    /**
     * Prepare data needed to install Magento
     */
    protected function _init()
    {
        $this->log("Preparing environment specific data.");

        $this->initRoutes();

        $relationships = $this->getRelationships();

        $this->dbHost = $relationships["database"][0]["host"];
        $this->dbName = $relationships["database"][0]["path"];
        $this->dbUser = $relationships["database"][0]["username"];
        $this->dbPassword = $relationships["database"][0]["password"];

        $this->adminUsername = isset($_ENV["ADMIN_USERNAME"]) ? $_ENV["ADMIN_USERNAME"] : "admin";
        $this->adminFirstname = isset($_ENV["ADMIN_FIRSTNAME"]) ? $_ENV["ADMIN_FIRSTNAME"] : "John";
        $this->adminLastname = isset($_ENV["ADMIN_LASTNAME"]) ? $_ENV["ADMIN_LASTNAME"] : "Doe";
        $this->adminEmail = isset($_ENV["ADMIN_EMAIL"]) ? $_ENV["ADMIN_EMAIL"] : "john@example.com";
        $this->adminPassword = isset($_ENV["ADMIN_PASSWORD"]) ? $_ENV["ADMIN_PASSWORD"] : "admin12";

        $this->redisHost = $relationships['redis'][0]['host'];
        $this->redisScheme = $relationships['redis'][0]['scheme'];
        $this->redisPort = $relationships['redis'][0]['port'];

        $this->solrHost = $relationships["solr"][0]["host"];
        $this->solrPath = $relationships["solr"][0]["path"];
        $this->solrPort = $relationships["solr"][0]["port"];
        $this->solrScheme = $relationships["solr"][0]["scheme"];
    }

    /**
     * Get routes information from Platform.sh environment variable.
     *
     * @return mixed
     */
    protected function getRoutes()
    {
        return json_decode(base64_decode($_ENV["PLATFORM_ROUTES"]), true);
    }

    /**
     * Get relationships information from Platform.sh environment variable. 
     *
     * @return mixed
     */
    protected function getRelationships()
    {
        return json_decode(base64_decode($_ENV["PLATFORM_RELATIONSHIPS"]), true);
    }

    /**
     * Run Magento installation
     */
    protected function installMagento()
    {
        $this->log("File local.xml does not exist. Installing Magento.");

        $urlUnsecure = $this->urls['unsecure'][''];
        $urlSecure = $this->urls['secure'][''];

        $this->execute(
            "php -f {$this->webRoot}/install.php -- \
            --default_currency $this->defaultCurrency \
            --url $urlUnsecure \
            --secure_base_url $urlSecure \
            --skip_url_validation 'yes' \
            --license_agreement_accepted 'yes' \
            --locale 'en_US' \
            --timezone 'America/Los_Angeles' \
            --db_host $this->dbHost \
            --db_name $this->dbName \
            --db_user $this->dbUser \
            --db_pass '$this->dbPassword' \
            --use_rewrites 'yes' \
            --use_secure 'yes' \
            --use_secure_admin 'yes' \
            --admin_username $this->adminUsername \
            --admin_firstname $this->adminFirstname \
            --admin_lastname $this->adminLastname \
            --admin_email $this->adminEmail \
            --admin_password $this->adminPassword"
        );
    }

    /**
     * Update Magento configuration
     */
    protected function updateMagento()
    {
        $this->log("File local.xml exists. Updating configuration.");

        $this->updateConfiguration();

        $this->updateDatabaseConfiguration();

        $this->updateSolrConfiguration();

        $this->updateUrls();

        $this->clearCache();
    }

    /**
     * Update admin credentials
     */
    protected function updateDatabaseConfiguration()
    {
        $this->log("Updating database configuration.");

        $this->execute("mysql -u user -h $this->dbHost -e \"update admin_user set firstname = '$this->adminFirstname', lastname = '$this->adminLastname', email = '$this->adminEmail', username = '$this->adminUsername', password = md5('$this->adminPassword') where user_id = '1';\" $this->dbName");
    }

    /**
     * Update SOLR configuration
     */
    protected function updateSolrConfiguration()
    {
        $this->log("Updating SOLR configuration.");

        $this->execute("mysql -u user -h $this->dbHost -e \"update core_config_data set value = '$this->solrHost' where path = 'catalog/search/solr_server_hostname' and scope_id = '0';\" $this->dbName");
        $this->execute("mysql -u user -h $this->dbHost -e \"update core_config_data set value = '$this->solrPort' where path = 'catalog/search/solr_server_port' and scope_id = '0';\" $this->dbName");
        $this->execute("mysql -u user -h $this->dbHost -e \"update core_config_data set value = '$this->solrScheme' where path = 'catalog/search/solr_server_username' and scope_id = '0';\" $this->dbName");
        $this->execute("mysql -u user -h $this->dbHost -e \"update core_config_data set value = '$this->solrPath' where path = 'catalog/search/solr_server_path' and scope_id = '0';\" $this->dbName");
    }

    /**
     * Update secure and unsecure URLs 
     */
    protected function updateUrls()
    {
        $this->log("Updating secure and unsecure URLs.");

        foreach ($this->urls as $urlType => $urls) {
            foreach ($urls as $route => $url) {
                $prefix = 'unsecure' === $urlType ? self::PREFIX_UNSECURE : self::PREFIX_SECURE;
                if (!strlen($route)) {
                    $this->execute("mysql -u user -h $this->dbHost -e \"update core_config_data set value = '$url' where path = 'web/$urlType/base_url' and scope_id = '0';\" $this->dbName");
                    continue;
                }
                $likeKey = $prefix . $route . '%';
                $likeKeyParsed = $prefix . str_replace('.', '---', $route) . '%';
                $this->execute("mysql -u user -h $this->dbHost -e \"update core_config_data set value = '$url' where path = 'web/$urlType/base_url' and (value like '$likeKey' or value like '$likeKeyParsed');\" $this->dbName");
            }
        }
    }

    /**
     * Clear content of temp directory
     */
    protected function clearTemp()
    {
        $this->log("Clearing temporary directory.");

        $this->execute('rm -rf init/*');
    }

    /**
     * Clear Magento file based cache
     *
     * @todo think about way to clean redis cache.
     */
    protected function clearCache()
    {
        $this->log("Clearing cache.");

        $this->execute("rm -rf {$this->webRoot}/var/cache/* {$this->webRoot}/var/full_page_cache/* {$this->webRoot}/media/css/* {$this->webRoot}/media/js/*");
    }

    /**
     * Update local.xml file content
     */
    protected function updateConfiguration()
    {
        $this->log("Updating local.xml database configuration.");

        $configFileName = "{$this->webRoot}/app/etc/local.xml";

        $config = simplexml_load_file($configFileName);

        $dbConfig = $config->xpath('/config/global/resources/default_setup/connection')[0];
        $cacheBackend = $config->xpath('/config/global/cache/backend');

        $dbConfig->username = $this->dbUser;
        $dbConfig->host = $this->dbHost;
        $dbConfig->dbname = $this->dbName;
        $dbConfig->password = $this->dbPassword;

        if (isset($cacheBackend[0]) && 'Cm_Cache_Backend_Redis' == $cacheBackend[0]) {
            $this->log("Updating local.xml Redis configuration.");

            $cacheConfig = $config->xpath('/config/global/cache/backend_options')[0];
            $fpcConfig = $config->xpath('/config/global/full_page_cache/backend_options')[0];
            $sessionConfig = $config->xpath('/config/global/redis_session')[0];

            $cacheConfig->port = $this->redisPort;
            $cacheConfig->server = $this->redisHost;

            $fpcConfig->port = $this->redisPort;
            $fpcConfig->server = $this->redisHost;

            $sessionConfig->port = $this->redisPort;
            $sessionConfig->host = $this->redisHost;
        }

        $config->saveXML($configFileName);
    }

    protected function log($message)
    {
        echo sprintf('[%s] %s', date("Y-m-d H:i:s"), $message) . PHP_EOL;
    }

    protected function execute($command)
    {
        if ($this->debugMode) {
            $this->log('Command:'.$command);
        }

        exec(
            $command,
            $this->lastOutput,
            $this->lastStatus
        );

        if ($this->debugMode) {
            $this->log('Status:'.var_export($this->lastStatus, true));
            $this->log('Output:'.var_export($this->lastOutput, true));
        }
    }
}
