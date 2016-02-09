<?
/*
 *	https://github.com/devsseb/lm-purchases
 *
 */

try {
	include 'varslib/varslib.inc.php';
	Debug::active();

	ini_set('opcache.enable', 0);
	session_start();

	if (is_file('config.inc.php')) {

		require 'config.inc.php';

/*
 * Authentication
 *
*/
		if (!exists($_SESSION, 'lm_user')) {

			if ($_POST) {
				$config = array(
					'lm_user' => '',
					'lm_password' => '',
					'db_server' => $db_server,
					'db_port' => $db_port,
					'db_user' => $db_user,
					'db_password' => $db_password,
					'db_database' => $db_database
				);
				if (exists($_POST, 'lm_user_save'))
					$config['lm_user'] = $_POST['lm_user'];
				if (exists($_POST, 'lm_password_save'))
					$config['lm_password'] = $_POST['lm_password'];
				writeConfig($config);

				$_SESSION['lm_user'] = $_POST['lm_user'];
				$_SESSION['lm_password'] = $_POST['lm_password'];
				$_SESSION['lm_checked'] = false;

				header('Location:' . $_SERVER['SCRIPT_URI']);
				exit();
			}
?>
			<!DOCTYPE html>
			<html>
				<head>
					<title>Leroy Merlin purchases authentication</title>
					<meta charset="UTF-8">
					<link rel="stylesheet" type="text/css" href="style.css" />
				</head>
				<body>
					<form method="post">
						<h1>Leroy Merlin authentication</h1>
						<p>
							<label>User : <input name="lm_user" required value="<?=toHtml($lm_user)?>"></label>
							<label><input type="checkbox" name="lm_user_save" value="1"<?=$lm_user ? ' checked' : ''?> /> save</label>
							<br />
							<label>Password : <input type="password" name="lm_password" required value="<?=toHtml($lm_password)?>"></label>
							<label><input type="checkbox" name="lm_password_save" value="1"<?=$lm_password ? ' checked' : ''?> /> save</label>
						</p>
<? 			if (exists($_GET, 'loginerror')) : ?>
						<p class="error">Authentication failure</p>
<? 			endif; ?>
						<p>
							<input type="submit" value="Login" />
						</p>
					</form>
				</body>
			</html>

<?
			exit();

		} else {

			if (!$_SESSION['lm_checked']) {

				$result = phantomjs($_SESSION['lm_user'], $_SESSION['lm_password'], 'login');
				if ($result->login == 'error') {
					session_destroy();
					header('Location:' . $_SERVER['SCRIPT_URI'] . '?loginerror');
					exit();
				}

				$_SESSION['lm_checked'] = true;
			}

			if (exists($_GET, 'logout')) {
				session_destroy();
				header('Location:' . $_SERVER['SCRIPT_URI']);
				exit();
			}

		}

/*
 * Manage DB
 *
*/
		$db = new PDO('mysql:host=' . $db_server . ';port=' . $db_port . ';dbname=' . $db_database, $db_user, $db_password);
		
		$tablesExists = (bool)sqlSelect('SHOW TABLES LIKE  "tickets"');

		if (!$tablesExists) {
			$db->exec('CREATE TABLE `tickets` (
				`id` int(11) NOT NULL AUTO_INCREMENT,
				`number` int(11) NOT NULL,
				`date` date NOT NULL,
				`location` varchar(255) NOT NULL,
				`price` float NOT NULL,
				PRIMARY KEY (`id`)
			) ENGINE=InnoDB AUTO_INCREMENT=164 DEFAULT CHARSET=utf8');
			$db->exec('CREATE TABLE `products` (
				`id` int(11) NOT NULL AUTO_INCREMENT,
				`ticket_id` int(11) NOT NULL,
				`reference` varchar(255) NOT NULL,
				`label` text NOT NULL,
				`image` longtext NOT NULL,
				`quantity` float NOT NULL,
				`price` float NOT NULL,
				`total` float NOT NULL,
				PRIMARY KEY (`id`)
			) ENGINE=InnoDB AUTO_INCREMENT=620 DEFAULT CHARSET=utf8');
		}

/*
 * Refresh tickets
 *
*/
		if (exists($_GET, 'askupdate')) {
?>

	<!DOCTYPE html>
	<html>
		<head>
			<title>Leroy Merlin purchases install</title>
			<meta charset="UTF-8">
		</head>
		<body>
			Retriving all tickets, please wait...
			<script>document.location.href="<?=$_SERVER['SCRIPT_URI'] . '?update'?>"</script>
		</body>
	</html>
			
<?
			exit();
		}

		if (exists($_GET, 'update')) {

			$tickets = phantomjs($_SESSION['lm_user'], $_SESSION['lm_password']);

			set_time_limit(120);

			$dbTickets = array();
			foreach (sqlSelect('SELECT number FROM tickets') as $dbTicket)
				$dbTickets[$dbTicket['number']] = true;

			foreach ($tickets as $ticket) {

				if (exists($dbTickets, $ticket->number))
					continue;

				$db->exec('
					INSERT INTO
						tickets
					SET
						number = ' . $db->quote($ticket->number) . ',
						date = ' . $db->quote($ticket->date) . ',
						location = ' . $db->quote($ticket->location) . ',
						price = ' . $db->quote($ticket->price) . '
					;
				');

				$ticket_id = $db->lastInsertId();

				foreach ($ticket->products as $product) {

					if (exists($product, 'image')) {
						file_put_contents($img = 'temp.' . pathinfo($product->image, PATHINFO_EXTENSION), file_get_contents($product->image));
						$image = 'data:' . mime_content_type($img) . ';base64,' . base64_encode(file_get_contents($img));
						unlink($img);
					} else
						$image = '';

					$db->exec('
						INSERT INTO
							products
						SET
							ticket_id = ' . $db->quote($ticket_id) . ',
							reference = ' . $db->quote($product->ref) . ',
							label = ' . $db->quote($product->label) . ',
							image = ' . $db->quote($image) . ',
							quantity = ' . $db->quote($product->quantity) . ',
							price = ' . $db->quote($product->price) . ',
							total = ' . $db->quote($product->total) . '
						;
					');

				}

			}

			header('Location:' . $_SERVER['SCRIPT_URI']);
			exit();

		}

/*
 * Display tickets
 *
*/
		$tickets = array();
		foreach (sqlSelect('SELECT * FROM tickets ORDER BY date DESC, number DESC') as $ticket)
			$tickets[$ticket['id']] = $ticket;

		foreach (sqlSelect('SELECT * FROM products') as $product)
			$tickets[$product['ticket_id']]['products'][] = $product;

		?>

		<!DOCTYPE html>
		<html>
			<head>
				<title>Leroy Merlin purchases</title>
				<meta charset="UTF-8">
				<link rel="stylesheet" type="text/css" href="style.css" />
			</head>
			<body>
				<p>
					<form class="form-button" method="post" action="?logout"><input type="submit" value="Logout" /></form>
					<form class="form-button" method="post" action="?askupdate"><input type="submit" value="Update tickets" /></form>
				</p>
				<table class="tickets">
					<thead>
						<tr>
							<th>Number</th>
							<th>Date</th>
							<th>Location</th>
							<th>Price</th>
							<th>Ref.</th>
							<th>Label</th>
							<th>Image</th>
							<th>Qty</th>
							<th>Price</th>
							<th>Total</th>
						</tr>
					</thead>
					<tbody>
				<? if (!$tickets) : ?>
						<tr>
							<td colspan="10" class="no-ticket">No ticket yet, please <a href="?askupdate">update</a></td>
						</tr>
				<? endif; ?>
				<? foreach ($tickets as $ticket) : ?>
						<tr>
							<td class="ticket" rowspan="<?=count($ticket['products'])?>"><?=toHtml($ticket['number'])?></td>
							<td class="ticket" rowspan="<?=count($ticket['products'])?>"><?=toHtml($ticket['date'])?></td>
							<td class="ticket" rowspan="<?=count($ticket['products'])?>"><?=toHtml($ticket['location'])?></td>
							<td class="ticket" rowspan="<?=count($ticket['products'])?>"><?=toHtml($ticket['price'])?></td>
					<? foreach ($ticket['products'] as $i => $product) : ?>
						<? if ($i) : ?>
						<tr>
						<? endif; ?>
							<td><a href="http://www.leroymerlin.fr/v3/search/search.do?keyword=<?=$product['reference']?>" target="_blank"><?=toHtml($product['reference'])?></a></td>
							<td><a href="http://www.leroymerlin.fr/v3/search/search.do?keyword=<?=$product['reference']?>" target="_blank"><?=toHtml($product['label'])?></a></td>
							<td><img src="<?=toHtml($product['image'])?>" /></td>
							<td><?=toHtml($product['quantity'])?></td>
							<td><?=toHtml($product['price'])?></td>
							<td><?=toHtml($product['total'])?></td>
							<? if ($i < count($ticket['products']) - 1) : ?>
						</tr>
						<? endif; ?>
					<? endforeach; ?>
						</tr>
				<? endforeach; ?>
					</tbody>
				</table>
			</body>
		</html>
<?

	} else {

/*
 * Install
 *
*/

		if ($_POST) {
			writeConfig($_POST);
			header('Location:' . $_SERVER['REQUEST_URI']);
			exit();
		}
?>
	<!DOCTYPE html>
	<html>
		<head>
			<title>Leroy Merlin purchases install</title>
			<meta charset="UTF-8">
		</head>
		<body>
			<form method="post">
				<h1>Database configuration</h1>
				<p>
					<label>Server : <input name="db_server" value="localhost" /></label><br />
					<label>Port : <input name="db_port" value="3306" /></label><br />
					<label>User : <input name="db_user" required /></label><br>
					<label>Password : <input name="db_password" required /></label><br>
					<label>Database : <input name="db_database" type="password" required /></label>
				</p>
				<h1>Leroy Merlin account</h1>
				<p>
					<label>User : <input name="lm_user" /></label> <em>Keep empty for ask later</em><br />
					<label>Password : <input name="lm_password" type="password" /></label> <em>Keep empty for ask later</em>
				</p>
				<p>
					<input type="submit" value="Install" />
				</p>
			</form>
		</body>
	</html>
<?

	}


/*
 * Exception
 *
*/

} catch (Exception $exception) {

	echo '<br/>Une erreur est survenue.<br/>';
	echo '<br>';
	echo '<strong>Message :</strong>' . toHtml($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '<br/>';
	echo '<strong>Fichier :</strong>' . toHtml($exception->getFile(), ENT_QUOTES, 'UTF-8') . '<br/>';
	echo '<strong>Ligne :</strong>' . toHtml($exception->getLine(), ENT_QUOTES, 'UTF-8') . '<br/>';
	echo '<strong>Pile :</strong>';
	echo '<br/>' . nl2br(toHtml($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8'));
	echo '<br/><br/><a href="./" title="Retour">Retour</a>';

}


/*
 * Functions
 *
*/

function writeConfig($config)
{
	file_put_contents('config.inc.php', '<?
	$lm_user = \'' . addslashes($config['lm_user']) . '\';
	$lm_password = \'' . addslashes($config['lm_password']) . '\';

	$db_server = \'' . addslashes($config['db_server']) . '\';
	$db_port = \'' . addslashes($config['db_port']) . '\';
	$db_user = \'' . addslashes($config['db_user']) . '\';
	$db_password = \'' . addslashes($config['db_password']) . '\';
	$db_database = \'' . addslashes($config['db_database']) . '\';

?>', LOCK_EX);
}

function phantomjs()
{
	$args = '';
	foreach (func_get_args() as $arg)
		$args.= ($args ? ' ' : '') . escapeshellarg($arg);
	$response = shell_exec(escapeshellarg(__DIR__ . '/phantomjs/phantomjs') . ' ' . escapeshellarg(__DIR__ . '/phantomjs/script.js') . ' ' . $args . ' 2>&1');
	preg_match('#exit\n(.*)#', $response, $response);
	$response = json_decode($response[1]);

	if (get($response, k('fatalerror')))
		throw new Exception('Phantomjs - ' . $response->message . ' ' . print_r($response->trace, true));

	return $response;

}

function sqlSelect($sql)
{
	$request = $GLOBALS['db']->query($sql);
	$result = $request->fetchAll();
	$request->closeCursor();
	unset($request);	

	return $result;
}

?>

