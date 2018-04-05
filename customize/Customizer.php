<?php

namespace CustomizeProject;

use Github\HttpClient\Message\ResponseMediator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

use Http\Adapter\Guzzle6\Client as GuzzleClient;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Rsa\Sha256;

class Customizer
{
    /**
     * It is necessary to explicitly include certain Guzzle files when
     * running code via a Composer script.
     */
    public static function loadGuzzleFunctions()
    {
        require __DIR__ . '/../vendor/guzzlehttp/guzzle/src/functions_include.php';
        require __DIR__ . '/../vendor/guzzlehttp/psr7/src/functions_include.php';
        require __DIR__ . '/../vendor/guzzlehttp/promises/src/functions_include.php';
    }

    public static function customize()
    {
        static::loadGuzzleFunctions();
        $customizer = new self();

        try
        {
            $customizer->run();
        }
        catch (\Exception $e)
        {
            print $e->getMessage() . "\n";
            exit ($e->getCode());
        }
    }

    /**
     * Create a new GitHub API client and authenticate.
     */
    public function createGitHubClient($token)
    {
        $this->gitHubAPI = new \Github\Client();
        $this->gitHubAPI->authenticate($token, null, \Github\Client::AUTH_HTTP_TOKEN);
    }

    /**
     * Get the currently-authenticated username
     */
    public function authenticatedUsername()
    {
        $authenticated = $this->gitHubAPI->api('current_user')->show();
        return $authenticated['login'];
    }

    public function run()
    {
        $this->github_token = getenv('GITHUB_TOKEN');

        // Providing TRAVIS_TOKEN is not required; GITHUB_TOKEN will
        // be used if a Travis-specific token is not available.
        $this->travis_token = getenv('TRAVIS_TOKEN');

        // Create an access token for Scrutinizer at:
        // https://scrutinizer-ci.com/profile/applications
        $this->scrutinizer_token = getenv('SCRUTINIZER_TOKEN');

        // Create an access token for Appveyor at:
        // https://ci.appveyor.com/api-token
        $this->appveyor_token = getenv('APPVEYOR_TOKEN');

        // TODO: Notify and quit if github_token is not provided, or fails to authenticate.
        $this->createGitHubClient($this->github_token);

        $this->working_dir = dirname(__DIR__);
        $this->project_name = basename($this->working_dir);
        $composer_path = $this->working_dir . '/composer.json';

        $composer_data = $this->readComposerJson($composer_path);

        $this->author_name = exec('git config user.name');
        $this->author_email = exec('git config user.email');
        $this->copyright_year = date('Y');
        $this->creation_date = date('Y/M/d');
        $this->project_camelcase_name = $this->camelCase($this->project_name);
        $this->project_org = getenv('GITHUB_ORG') ?: $this->authenticatedUsername();
        $this->project_name_and_org = $this->project_org . '/' . $this->project_name;

        // Copy contents of templates directory over the working directory
        $this->placeTemplates();

        // Composer customizations:
        //    1. Change project name
        //    2. Remove "CustomizeProject\\" from psr-4 autoloader
        //    3. Remove customize and post-install scripts
        $this->adjustComposerJson($composer_path, $composer_data);

        // General replacements:
        //    1. Project
        //       a. Project name (e.g. example-project)
        //       b. Project camelcase name (e.g. ExampleProject)
        //       c. Project organization (e.g. example-org)
        //    2. Credits
        //       a. Author name
        //       b. Author email address
        //       c. Copyright date
        // Note that these apply to all files in the project, including
        // composer.json (also customized above).
        $replacements = [
            '/{{CREATION_DATE}}/' => $this->creation_date,
            '/{{PROJECT}}/' => $this->project_name,
            '/{{PROJECT_CAMELCASE_NAME}}/' => $this->project_camelcase_name,
            '/{{ORG}}/' => $this->project_org,
            '/example-project/' => $this->project_name,
            '/ExampleProject/' => $this->project_camelcase_name,
            '/example-org/' => $this->project_org,
            "#{$composer_data['name']}#" => $this->project_org . '/' . $this->project_name,
            '#' . $this->camelCase($this->project_name) . '#' => $this->project_camelcase_name,
            "/{$composer_data['authors'][0]['name']}/" => $this->author_name,
            "/{$composer_data['authors'][0]['email']}/" => $this->author_email,
            '/Copyright (c) [0-9]*/' => "Copyright (c) " . $this->copyright_year,
            '/{{TEMPLATE_PROJECT}}/' => $this->project_name,
            '/{{TEMPLATE_ORG}}/' => $this->project_org,
        ];
        $replacements = array_filter($replacements);
        $this->replaceContentsOfAllTemplateFiles($replacements);

        // Additional cleanup:
        //    1. Remove 'customize' directory
        $this->cleanupCustomization();

        // Update our dependencies after customizing
        $this->passthru('composer -n update');

        // Sanity checks post-customization
        //    1. Dump the autoload file
        //    2. Run the tests
        $this->passthru('composer -n dumpautoload');
        $this->passthru('composer -n test');

        // If the existing repository was not preserved, then create
        // a new empty repository now.
        if (!is_dir('.git')) {
            $this->passthru('git init');
            $this->passthru('git add .');
            $this->passthru('git commit -m "Initial commit of unmodified template project."');
        }
        else {
            // If we are re-using an existing repo, make sure that the
            // origin is set correctly. If there is no origin, then
            // 'hub' will set the origin.
            @passthru("git remote set-url origin git@github.com:{$this->project_name_and_org}.git");
            @passthru("git remote set-url origin --push git@github.com:{$this->project_name_and_org}.git");
            // Remove the 'composer' remote if it exists.
            @passthru("git remote remove composer");
        }

        // Repository creation:
        //    1. Add a commit that explains all of the changes made to project.
        //    2. Create a GitHub repository via `hub create`
        //    3. Push code to GitHub
        $this->createRepository();

        // Testing:
        //    1. Enable testing on Travis via `travis enable`
        //    2. Enable testing on AppVeyor
        //    3. Enable coveralls (TODO API not available)
        $this->enableTesting();

        // Make initial commit.
        // TODO: Make a more robust commit message including everthing that was done.
        $this->passthru('git add .');
        $this->passthru('git commit -m "Template project customizations."');

        // Push repository to fire off a build
        $this->passthru("git push -u origin master");

        // Code analysis:
        //    1. Enable testing via Scrutinizer
        $this->enableScrutinizer($this->project_name_and_org);

        // Composer:
        //    1. Register with packagist?  (TODO API not available)
        //    2. Register with dependencies.io (TODO API not available)
    }

    protected function readComposerJson($composer_path)
    {
        $composer_contents = file_get_contents($composer_path);
        return json_decode($composer_contents, true);
    }

    protected function adjustComposerJson($composer_path, $composer_data)
    {
        // Fix the name
        $composer_data['name'] = $this->project_name_and_org;

        // Remove parts of autoloader that are no longer going to be used.
        unset($composer_data['autoload']['psr-4']['CustomizeProject\\']);

        // Remove unused scripts.
        unset($composer_data['scripts']['customize']);
        unset($composer_data['scripts']['post-install-cmd']);

        file_put_contents($composer_path, json_encode($composer_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function placeTemplates()
    {
        $fs = new Filesystem();
        $fs->mirror($this->working_dir . '/customize/templates', $this->working_dir);
    }

    protected function cleanupCustomization()
    {
        $fs = new Filesystem();
        $fs->remove($this->working_dir . '/customize');
    }

    protected function createRepository()
    {
        // TODO: ensure that 'hub' is installed and print an error message if it isn't.
        passthru("hub create " . $this->project_name_and_org);
    }

    protected function enableTesting()
    {
        $this->enableTravis();
        $this->enableAppveyor($this->project_name_and_org);
    }

    protected function enableTravis()
    {
        // If there is no travis token, log in with the github token
        if (empty($this->travis_token)) {
            passthru("travis login --no-interactive --github-token '{$this->github_token}'");
        }
        else {
            passthru("travis login --no-interactive --token '{$this->travis_token}'");
        }
        // Problem: creating github via 'hub' syncs Travis, causes a failure here.
        // repository not known to Travis CI (or no access?)
        // triggering sync: 409: "{\"message\":\"Sync already in progress. Try again later.\"}"
        passthru('travis sync  --no-interactive --check');

        // Begin testing this repository
        passthru("travis enable --no-interactive", $status);

        // If 'travis enable' did not work, perhaps Travis needs more
        // time before the new GitHub repository shows up.
        // TODO: We should *eventually* give up.
        while ($status != 0) {
            print "Waiting for GitHub to advertise the new repository...\n";
            sleep(10);
            passthru('travis sync  --no-interactive');
            passthru("travis enable --no-interactive", $status);
        }
    }

    protected function enableAppveyor($project)
    {
        if (!$this->appveyor_token) {
            print "No APPVEYOR_TOKEN environment variable provided; skipping Appveyor setup.\n";
            return;
        }

        $uri = 'projects';
        $data = [
            "repositoryProvider" => "gitHub",
            "repositoryName" => "$project",
        ];
        $this->appveyorAPI($uri, $this->appveyor_token, $data);
    }

    function appveyorAPI($uri, $token, $data = [], $method = 'GET')
    {
        $url = "https://ci.appveyor.com/api/$uri";
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'my-org/my-app',
            'Authorization' => "Bearer " . $token,
        ];
        $guzzleParams = [ 'headers' => $headers, ];
        if (!empty($data)) {
            $method = 'POST';
            $guzzleParams['json'] = $data;
        }
        $client = new \GuzzleHttp\Client();
        $res = $client->request($method, $url, $guzzleParams);
        $resultData = json_decode($res->getBody(), true);
        $httpCode = $res->getStatusCode();
        if ($httpCode >= 300) {
            throw new \Exception("Appveyor API call $uri failed with $httpCode");
        }

        return $resultData;
    }

    protected function enableScrutinizer($project)
    {
        if (!$this->scrutinizer_token) {
            print "No SCRUTINIZER_TOKEN environment variable provided; skipping Scrutinizer setup.\n";
            return;
        }

        $uri = 'repositories/g';
        $data = ['name' => $project];
        $this->scrutinizerAPI($uri, $this->scrutinizer_token, $data);

        $uri = "repositories/g/$project/inspections";
        $data = ['branch' => 'master'];
        $this->scrutinizerAPI($uri, $this->scrutinizer_token, $data);
    }

    function scrutinizerAPI($uri, $token, $data = [], $method = 'GET')
    {
        $url = "https://scrutinizer-ci.com/api/$uri?access_token=$token";
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'my-org/my-app',
        ];
        $guzzleParams = [ 'headers' => $headers, ];
        if (!empty($data)) {
            $method = 'POST';
            $guzzleParams['json'] = $data;
        }
        $client = new \GuzzleHttp\Client();
        $res = $client->request($method, $url, $guzzleParams);
        $resultData = json_decode($res->getBody(), true);
        $httpCode = $res->getStatusCode();
        if ($httpCode >= 300) {
            throw new \Exception("Scrutinizer API call $uri failed with $httpCode");
        }

        return $resultData;
    }

    protected function replaceContentsOfAllTemplateFiles($replacements)
    {
        $files = Finder::create()
            ->files()
            ->exclude('customize')
            ->exclude('vendor')
            ->in(dirname(__DIR__));
        foreach ($files as $file) {
            $this->replaceContentsOfFile($replacements, $file);
        }
    }

    protected function replaceContentsOfFile($replacements, $file)
    {
        if (empty($file->getRealPath())) {
            return;
        }
        print "Replace " . $file->getRelativePathname() . "\n";
        $contents = file_get_contents($file->getRealPath());
        $altered = preg_replace(array_keys($replacements), array_values($replacements), $contents);
        if ($altered != $contents) {
            file_put_contents($file->getRealPath(), $altered);
        }
    }

    protected function camelCase($str)
    {
        return str_replace('-', '', ucwords($str, '-'));
    }

    protected function passthru($cmd)
    {
        passthru($cmd, $status);
        if ($status != 0) {
            throw new \Exception('Command failed with exit code ' . $status);
        }
    }
}
