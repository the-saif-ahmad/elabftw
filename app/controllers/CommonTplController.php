<?php
/**
 * app/controllers/CommenTplController.php
 *
 * @author Nicolas CARPi <nicolas.carpi@curie.fr>
 * @copyright 2012 Nicolas CARPi
 * @see http://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

namespace Elabftw\Elabftw;

/**
 * Controller for the common experiment template
 *
 */
require_once '../../inc/common.php';

try {
    $commonTpl = new CommonTpl();

    // DEFAULT EXPERIMENT TEMPLATE
    if (isset($_POST['commonTplUpdate'])) {
        $commonTpl->update($_POST['commonTplUpdate'], $_SESSION['team_id']);
    }

} catch (Exception $e) {
    dblog('Error', $_SESSION['userid'], $e->getMessage());
}