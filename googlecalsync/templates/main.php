<?php
/** @var array $_ */
\OCP\Util::addScript('googlecalsync', 'googlecalsync-main');
\OCP\Util::addStyle('googlecalsync', 'googlecalsync');
?>
<div id="googlecalsync-app" data-user="<?php echo \OCP\Util::sanitizeHTML($_['user_id']); ?>"></div>
