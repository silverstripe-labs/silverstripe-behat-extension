<?php

namespace SilverStripe\BehatExtension\Compiler;

use SilverStripe\Control\CLIRequestBuilder;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPApplication;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\CoreKernel;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\ORM\DataObject;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

/**
 * Loads SilverStripe core. Required to initialize autoloading.
 */
class CoreInitializationPass implements CompilerPassInterface
{
    /**
     * Loads kernel file.
     *
     * @param ContainerBuilder $container
     */
    public function process(ContainerBuilder $container)
    {
        // Note: Moved from SilverStripeAwareInitializer
        file_put_contents('php://stdout', 'Bootstrapping' . PHP_EOL);

        // Connect to database and build manifest
        if (!getenv('SS_ENVIRONMENT_TYPE')) {
            putenv('SS_ENVIRONMENT_TYPE=dev');
        }

        // Include bootstrap file
        $bootstrapFile = $container->getParameter('silverstripe_extension.bootstrap_file');
        if ($bootstrapFile) {
            require_once $bootstrapFile;
        }

        // Copied from SapphireTest
        $request = CLIRequestBuilder::createFromEnvironment();
        $kernel = new CoreKernel(BASE_PATH);
        $app = new HTTPApplication($kernel);
        $app->execute($request, function (HTTPRequest $request) {
            // Start session and execute
            $request->getSession()->init($request);

            // Invalidate classname spec since the test manifest will now pull out new subclasses for each internal class
            // (e.g. Member will now have various subclasses of DataObjects that implement TestOnly)
            DataObject::reset();

            // Set dummy controller;
            $controller = Controller::create();
            $controller->setRequest($request);
            $controller->pushCurrent();
            $controller->doInit();
        }, true);

        // Register all paths
        foreach (ModuleLoader::inst()->getManifest()->getModules() as $module) {
            $container->setParameter('paths.modules.'.$module->getShortName(), $module->getPath());
            $composerName = $module->getComposerName();
            if ($composerName) {
                list($vendor,$name) = explode('/', $composerName);
                $container->setParameter('paths.modules.'.$vendor.'.'.$name, $module->getPath());
            }
        }

        // Remove the error handler so that PHPUnit can add its own
        restore_error_handler();
    }
}
