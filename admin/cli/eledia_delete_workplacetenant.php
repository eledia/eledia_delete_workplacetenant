<?php

define('CLI_SCRIPT', true);

use tool_tenant\tenancy;
use tool_tenant\manager;
use tool_tenant\tenant_group;

require(__DIR__ . '/../../config.php');
require_once("$CFG->libdir/clilib.php");

// Now get cli options.
list($options, $unrecognized) = cli_get_params(array('help' => false,  'delete' => false, 'list' => false),
        array('h' => 'help', 'l' => 'list', 'd' => 'delete'));

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help =
    "Delete tenants and all associated items.
     Following items are deleted:
     - Reports
     - Dynamic rules
     - Organisations
     - Users
     - Courses
     - Course categories
     - Programs
     - Certifications
     - Tenant groups in shared courses
     - Tenant pages
     Unrelated items like cohorts are not deleted.


Options:
-h, --help            Print out this help
-l, --list            List tenants
--delete              Delete tenant

Example:
\$ sudo -u www-data /usr/bin/php admin/cli/eledia_delete_workplacetenant.php --delete=<tenant ID>
";

    echo $help;
    die;
}

// if (empty($options['verbose'])) {
//    $trace = new null_progress_trace();
// } else {
//    $trace = new text_progress_trace();
// }
$trace = new null_progress_trace();
$error = false;
/*require_once($CFG->dirroot."/admin/tool/eledia_scripts/lib.php");*/
/*$helper = new eledia_scripts_helper();*/
/*$helper->export_coursecertificate_files($options);*/
if ($options['list']) {
    $tenants = tenancy::get_tenants();
    foreach ($tenants as $tenant) {
        cli_writeln("name:  $tenant->name \t\tID: $tenant->id\tparentid: " . var_export($tenant->parentid, true) . "\thaschildren: " . var_export($tenant->haschildren, true));
    }
}

if ($options['delete']) {
    $tenants = tenancy::get_tenants();
    foreach ($tenants as $tenant) {
        if (is_number($options['delete']) && (int)$tenant->id === (int) $options['delete']) {
            cli_writeln("name:  $tenant->name \t\tid: $tenant->id\tparentid: " . var_export($tenant->parentid, true) . "\thaschildren: " . var_export($tenant->haschildren, true));
            if ($tenant->haschildren) {
                $answer = cli_input('Tenant has children. Delete recursively? (y/N)');
                if (strtolower($answer) !== 'y') { 
                    cli_writeln('aborted...exit');
                    exit;
                }
                delete_recursive($tenant);
                exit;
            }
            delete_tenant($tenant);
            exit;
        }
    }
}
// Helper functions.
// Following items are deleted by event:
// - Reports
// - Dynamic rules
// - Organisations
// - Programs
// - Certifications

function delete_users(stdClass $tenant) {
    // get all tenants users.
    global $DB, $CFG;
    require_once($CFG->dirroot."/user/lib.php");
    $users = $DB->get_recordset('tool_tenant_user', [ 'tenantid' => $tenant->id ]);
    $deleteerror = [];
    cli_writeln('deleting users...');
    try {
        foreach ($users as $user) {
            $u = $DB->get_record('user', ['id' => $user->userid ]);
            if (!$u)
            continue;

            cli_writeln("Username: $u->username\t Userid: $u->id");
            try {
            if(!user_delete_user($u)) {
                    cli_writeln("Could not delete user $u->name with id $u->id. Please delete manually.");
                }
            } catch (Exception $e) {
                $deleteerror[] = $user->userid;
            }
        }
    } catch (\Exception $e) {
        $users->close();
        cli_error("Error in database operation: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
    $users->close();
    foreach ($deleteerror as $d) {
        cli_writeln("Error while deleting user $d");
    }
    cli_writeln('Done');
}

function delete_coursecategories(stdClass $tenant) {
    $category = core_course_category::get($tenant->categoryid, IGNORE_MISSING, true);
    cli_writeln("Deleting tenant category $category->name with id $category->id.");
    cli_writeln("All sub categories and courses are deleted recursively.");
    $category->delete_full(false);
    cli_writeln('Done');
}

// Pages should be deleted through persistant model.
// function delete_pages(stdClass $tenant) {
// }

function delete_recursive(stdClass $tenant) {
    // If no children start delete process
    cli_writeln('Deleting tenants recursively.');
    if (!$tenant->haschildren) {
        delete_tenant($tenant);
        cli_writeln('Done');
        return;
    }
    // Foreach tenant get children
    $children = tenant_get_children($tenant);
    foreach ($children as $child) {
        delete_recursive($child);
    }
}

function delete_tenant(stdClass $tenant) {
    tenant_deleteprocess($tenant);
    $m = new manager();
    $m->archive_tenant($tenant->id);
    $m->delete_tenant($tenant->id);
}

function tenant_get_children(stdClass $tenant): array {
    $childs = tenancy::get_tenants();
    foreach ($childs as $key => $child) {
        if ($child->parentid !== $tenant->id) {
            unset($childs[$key]);
        }
    }
    return $childs;
}

function tenant_deleteprocess(stdClass $tenant) {
    delete_users($tenant);
    tenant_group::delete_for_tenant($tenant->id);
    delete_coursecategories($tenant);
    // Pages should be deleted through persistant model. Nothing to do here.
    // delete_pages($tenant);
}
