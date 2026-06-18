<?php
declare(strict_types=1);

function notfound(): void {
	global $template;
	$template->set('noextra','1');
}

function permissions(): void {
	global $template;
	$template->set('noextra','1');
}

function noaccounts(): void {
	global $template;
	$template->set('noextra','1');
}