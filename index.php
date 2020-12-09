<?php

session_name("MEMES");
session_start();

$domain = 'http' . (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'];

$dbfile = '../db/images.db';
if ($_SERVER['SERVER_NAME'] === 'localhost')
	$dbfile = 'images.db';
$db = new SQLite3($dbfile);

$font = '/usr/share/fonts/TTF/Impact.TTF'; // ttf-ms-fonts in aur
$size = 36;

function drawText($image, $color, $stroke, $text, $x, $y) {
	global $font, $size;

	// Can fake stroke by drawing the font in black offset some px in all 4 directions
	imagettftext($image, $size, 0, $x+3, $y+3, $stroke, $font, $text);
	imagettftext($image, $size, 0, $x+3, $y-3, $stroke, $font, $text);
	imagettftext($image, $size, 0, $x-3, $y+3, $stroke, $font, $text);
	imagettftext($image, $size, 0, $x-3, $y-3, $stroke, $font, $text);

	imagettftext($image, $size, 0, $x, $y, $color, $font, $text);
}

function genImage($row, $secure = false) {
	global $font, $size;

	$imagefile = 'var/' . $row['id'] . '.' . $row['ext'];
	$image;
	switch ($row['ext']) {
	case 'jpg':
	case 'jpeg':
		$image = imagecreatefromjpeg($imagefile);
		break;
	case 'png':
		$image = imagecreatefrompng($imagefile);
		break;
	case 'gif':
		$image = imagecreatefromgif($imagefile);
		break;
	default:
		// TODO: error handling, etc
		return;
	}
	$color = imagecolorallocate($image, 255, 255, 255); // white
	$stroke = imagecolorallocate($image, 0, 0, 0); // black

	$width = imagesx($image);
	$height = imagesy($image);

	if (!empty($row['top'])) {
		$coords = imageftbbox($size, 0, $font, $row['top']);
		drawText(
			$image, $color, $stroke, $row['top'],
			$coords[0] + ($width - $coords[4]) / 2,
			($coords[1] - $coords[5]) + $height * 0.05
		);
	}

	if (!empty($row['bottom'])) {
		$coords = imagettfbbox($size, 0, $font, $row['bottom']);
		drawText(
			$image, $color, $stroke, $row['bottom'],
			$coords[0] + ($width - $coords[4]) / 2,
			-($coords[1] - $coords[5]) / 2 + $height * 0.95
		);
	}

	if ($secure && !empty($row['secret'])) {
		$coords = imagettfbbox($size, 0, $font, $row['secret']);
		drawText(
			$image, $color, $stroke, $row['secret'],
			$coords[0] + ($width - $coords[4]) / 2,
			($coords[1] - $coords[5]) / 2 + ($height * 0.35)
		);

		// Add text - "now, reload the page" to whatever they say
		$text = 'Now, reload the page.';
		$coords = imagettfbbox($size, 0, $font, $text);
		drawText(
			$image, $color, $stroke, $text,
			$coords[0] + ($width - $coords[4]) / 2,
			$coords[1] + ($height * 0.7) + ($coords[5] / 2)
		);
	}

	// output
	switch($row['ext']) {
	case 'jpg':
	case 'jpeg':
		header('Content-Type: image/jpeg');
		imagejpeg($image);
		break;
	case 'png':
		header('Content-Type: image/png');
		imagepng($image);
		break;
	case 'gif':
		header('Content-Type: image/gif');
		imagegif($image);
		break;
	}
	imagedestroy($image);
}

$baseUrl = substr($_SERVER['SCRIPT_NAME'], 0, stripos($_SERVER['SCRIPT_NAME'], 'index.php'));
$path = isset($_SERVER['PATH_INFO']) ? explode('/', substr($_SERVER['PATH_INFO'], 1)) : [];

if (isset($path[0]) && $path[0] == 'preview') {
	genImage($_SESSION['meme'], array_key_exists('secure', $_GET));
	die;
} else if (isset($path[0])) {
	// find image in db, check if already served, if so serve w/o hidden text
	$statement = $db->prepare('SELECT * FROM images WHERE id = :id');
	$statement->bindValue(':id', $path[0]);
	$row = $statement->execute()->fetchArray(SQLITE3_ASSOC);

	if (!$row) {
		http_response_code(404);
		echo 'File not found';
	} else {
		if (!$row['shown']) {
			$update = $db->prepare('UPDATE images SET shown = true WHERE id = :id');
			$update->bindValue(':id', $row['id']);
			$update->execute();
		}
		genImage($row, !$row['shown']);
	}
	die;
} else if (!empty($_POST)) {
	if (array_key_exists('approve', $_POST)) {
		$insert = $db->prepare('INSERT INTO images VALUES (:id, :ext, :top, :bottom, :secret, false)');
		$insert->bindValue(':id', $_SESSION['meme']['id']);
		$insert->bindValue(':ext', $_SESSION['meme']['ext']);
		$insert->bindValue(':top', $_SESSION['meme']['top']);
		$insert->bindValue(':bottom', $_SESSION['meme']['bottom']);
		$insert->bindValue(':secret', $_SESSION['meme']['secret']);
		$insert->execute();
	} else {
		// TODO: test if file not supplied; the rest mostly optional

		$ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
		$id = substr($_FILES['image']['tmp_name'], 8);
		$name = $id . '.' . $ext;

		// TODO: ensure can process the image as file extension before saving it
		move_uploaded_file($_FILES['image']['tmp_name'], 'var/' . $name);

		$_SESSION['meme'] = array(
			'id' => $id,
			'ext' => $ext,
			'top' => $_POST['top'],
			'bottom' => $_POST['bottom'],
			'secret' => $_POST['secret'],
		);

		// TODO: cleanup; cron? - remove images not in database due to not being saved
	}
} else if (array_key_exists('approve', $_POST)) {
}
// Needs a preview mode, before saving; just always generate it on the fly, store the strings in the db
// "Generate Safe URL" for the share button
?>
<!doctype html>
<html>
<head>
	<title>Meme Maker</title>
	<link rel="stylesheet" href="style.css">
</head>
<body>
<?php if (empty($_POST)): ?>
<form method="POST" enctype="multipart/form-data">
	<div>
		<label for="top-input">Top text:</label>
		<input id="top-input" name="top" type="text" <?= empty($_POST['top']) ? '' : ('value="' . htmlspecialchars($_POST['top']) . '"') ?>/>
	</div>
	<div>
		<label for="bottom-input">Bottom text:</label>
		<input id="bottom-input" name="bottom" type="text" <?= empty($_POST['bottom']) ? '' : ('value="' . htmlspecialchars($_POST['bottom']) . '"') ?>/>
	</div>
	<div>
		<label for="secret-input">Hidden text:</label>
		<textarea id="secret-input" name="secret"><?= empty($_POST['secret']) ? '' : htmlspecialchars($_POST['secret']) ?></textarea>
	</div>
	<div>
		<label for="image-input">Image:</label>
		<input id="image-input" name="image" type="file" />
	</div>
	<input type="submit" />
</form>
<?php elseif (!array_key_exists('approve', $_POST)): ?>
<form method="POST">
	<p>Don't share these previews! If they look good, click "Generate Safe URL" for a safely sharable URL</p>
	<img src="/index.php/preview" />
	<img src="/index.php/preview?secure" />
	<input type="hidden" name="approve" />
	<button type="submit">Generate Safe URL</button>
</form>
<?php else: ?>
	<p>Please share this URL: <span><?= $domain . $baseUrl . 'index.php/' . $_SESSION['meme']['id'] ?></span></p>
<?php endif; ?>
</body>
</html>
