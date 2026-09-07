<?php
/**
 * Watchful audit class.
 *
 * @version   2016-12-20 11:41 UTC+01
 * @package   Watchful WP Client
 * @author    Watchful
 * @authorUrl https://watchful.net
 * @copyright Copyright (c) 2020 watchful.net
 * @license   GNU/GPL
 */

namespace Watchful\Controller;

use stdClass;
use Watchful\Audit\AbstractAudit;
use Watchful\Audit\Files\FilesPermissions;
use Watchful\Audit\Files\FoldersPermissions;
use Watchful\Audit\Files\Integrity;
use Watchful\Audit\Files\MalwareScanner;
use Watchful\Audit\ScannerResponse;
use Watchful\Audit\Tests\HasBadKeys;
use Watchful\Audit\Tests\HasConfigChmod;
use Watchful\Audit\Tests\HasDBPrefix;
use Watchful\Audit\Tests\HasDbWeakPassword;
use Watchful\Audit\Tests\HasDeactivatedPlugins;
use Watchful\Audit\Tests\HasDeactivatedThemes;
use Watchful\Audit\Tests\HasExpiringCertificate;
use Watchful\Audit\Tests\HasInstallOnSubdirectory;
use Watchful\Audit\Tests\HasPhpVersion;
use Watchful\Audit\Tests\HasReadme;
use Watchful\Audit\Tests\HasRootArchives;
use Watchful\Audit\Tests\HasSecurityHeaders;
use Watchful\Audit\Tests\HasThemesToUpdate;
use Watchful\Audit\Tests\HasUnnecessaryLoginInfo;
use Watchful\Audit\Tests\HasUnsupportedPhpVersion;
use Watchful\Audit\Tests\HasWPAdminUser;
use Watchful\Audit\Tests\HasWPHtaccess;
use Watchful\Audit\Tests\HasWritablePhpFiles;
use Watchful\Audit\Tests\HasWpVersion;
use Watchful\Audit\Tests\HaveAdminsWeakPassword;
use Watchful\Audit\Tests\IsFileEditorDisabled;
use Watchful\Audit\Tests\IsHttpsEnforced;
use Watchful\Audit\Tests\IsXmlRpcDisabled;
use Watchful\Audit\Tests\IsDBDebugEnabled;
use Watchful\Audit\Tests\IsDebugEnabled;
use Watchful\Audit\Tests\IsDebugLogAvailable;
use Watchful\Audit\Tests\IsScriptDebugEnabled;
use Watchful\Audit\Tests\IsUploadBrowsable;
use Watchful\Audit\Tests\RobotsTxt;
use Watchful\Exception;
use Watchful\Helpers\Authentification;
use Watchful\Helpers\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Watchful Scanner class.
 */
class Audit implements BaseControllerInterface
{
    private $logger;

    public function __construct()
    {
        $this->logger = new Logger('audit');
    }

    /**
     * Audit the REST request.
     *
     * @param WP_REST_Request $request The WP REST request object.
     *
     * @return WP_REST_Response
     *
     * @throws Exception If scanner task method doesn't exist.
     * @throws \Exception
     */
    public function audit(WP_REST_Request $request): WP_REST_Response
    {
        $task               = $request->get_param('task');
        $start              = $request->get_param('start') ? (int)$request->get_param('start') : 0;
        $class_name         = $request->get_param('class_name');
        $max_execution_time = $request->get_param('max_execution_time') ? (int)$request->get_param('max_execution_time') : null;
        $signatures_beta    = (bool)$request->get_param('signaturesBeta');

        $this->logger->info('Audit request received', ['task' => $task]);

        switch ($task) {
            case 'auditConfiguration':
                $result = $this->auditConfiguration($start, $class_name, $max_execution_time);
                break;
            case 'auditMalwareScanner':
                $result = $this->auditMalwareScanner($start, $max_execution_time, $signatures_beta);
                break;
            case 'auditFoldersPermissions':
                $result = $this->auditFoldersPermissions($start, $max_execution_time);
                break;
            case 'auditFilesPermissions':
                $result = $this->auditFilesPermissions($start, $max_execution_time);
                break;
            case 'auditCoreIntegrity':
                $result = $this->auditCoreIntegrity($start, $max_execution_time);
                break;
            default:
                throw new Exception('bad-task', 403);
        }

        $this->logger->info('Audit finished', ['task' => $task]);

        return new WP_REST_Response($result);
    }

    public function auditConfiguration(int $start, ?string $class_name, ?int $max_execution_time = null): stdClass
    {
        $start_time = microtime(true);
        $this->init_audit();

        $wp_audit       = new stdClass();
        $wp_audit->step = new stdClass();

        $tests = [
            HasBadKeys::class,
            HasConfigChmod::class,
            HasDBPrefix::class,
            HasDbWeakPassword::class,
            HasDeactivatedPlugins::class,
            HasDeactivatedThemes::class,
            HasInstallOnSubdirectory::class,
            HasPhpVersion::class,
            HasUnsupportedPhpVersion::class,
            HasWritablePhpFiles::class,
            HasSecurityHeaders::class,
            IsHttpsEnforced::class,
            HasExpiringCertificate::class,
            HasRootArchives::class,
            IsFileEditorDisabled::class,
            IsXmlRpcDisabled::class,
            HasReadme::class,
            HasThemesToUpdate::class,
            HasUnnecessaryLoginInfo::class,
            HasWPAdminUser::class,
            HasWPHtaccess::class,
            HasWpVersion::class,
            HaveAdminsWeakPassword::class,
            IsDebugEnabled::class,
            IsDBDebugEnabled::class,
            IsDebugLogAvailable::class,
            IsScriptDebugEnabled::class,
            IsUploadBrowsable::class,
            RobotsTxt::class,
        ];

        if (!empty($class_name)) {
            $this->logger->info('Starting from specific test', ['class_name' => $class_name]);
            $tests = array_slice($tests, array_search($class_name, $tests, true));
        }

        foreach ($tests as $test_class) {
            $this->logger->info('Starting test', ['class_name' => $test_class]);

            /** @var AbstractAudit $test */
            $test = new $test_class($start_time, $max_execution_time);

            $class_name = explode('\\', $test_class);
            $class_name = end($class_name);

            $wp_audit->step->class_name = $class_name;
            $wp_audit->step->completed  = false;

            if ($test->have_time() === false) {
                break;
            }

            $results               = $test->run($start);
            $wp_audit->$class_name = $results;

            if ($results->error === ScannerResponse::TIMEOUT_ERROR_CODE) {
                $wp_audit->step->start = $results->values;
                break;
            }

            $wp_audit->step->completed = true;
        }

        $this->logger->info('Audit finished', ['step' => $wp_audit->step]);

        return $wp_audit;
    }

    /**
     * This method is called only once when the audit starts.
     * We can do some initializations here before actually starting the audit.
     *
     * @return void
     */
    private function init_audit()
    {
        // Remove the filesystem cache for the WP root.
        wp_cache_delete(ABSPATH, 'watchful.audit.recursiveListing');
    }

    /**
     * @throws \Exception
     */
    public function auditMalwareScanner(int $start, ?int $max_execution_time = null, bool $signatures_beta = false): stdClass
    {
        $scanner = new MalwareScanner(null, $max_execution_time);

        return $scanner->run($start, $signatures_beta);
    }

    public function auditFoldersPermissions(int $start, ?int $max_execution_time = null): stdClass
    {
        $scanner = new FoldersPermissions(null, $max_execution_time);

        return $scanner->run($start);
    }

    public function auditFilesPermissions(int $start, ?int $max_execution_time = null): stdClass
    {
        $scanner = new FilesPermissions(null, $max_execution_time);

        return $scanner->run($start);
    }

    /**
     * @throws \Exception
     */
    public function auditCoreIntegrity(int $start, ?int $max_execution_time = null): stdClass
    {
        $model = new Integrity(null, $max_execution_time);

        return $model->run($start);
    }

    /**
     * Register WP REST API routes.
     */
    public function register_routes()
    {
        register_rest_route(
            'watchful/v1',
            '/audit',
            array(
                array(
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => array($this, 'audit'),
                    'permission_callback' => array('Watchful\Routes', 'authentification'),
                    'args' => array_merge(
                        Authentification::get_arguments(),
                        array(
                            'start' => array(
                                'default' => 0,
                                'sanitize_callback' => 'absint',
                            ),
                            'max_execution_time' => array(
                                'default' => null,
                                'sanitize_callback' => 'absint',
                            ),
                            'task' => array(
                                'default' => null,
                            ),
                            'class_name' => array(
                                'default' => null,
                            ),
                        )
                    ),
                ),
            )
        );
    }
}
