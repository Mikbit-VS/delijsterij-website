<?php
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo "Dit script kan niet direct geopend worden.";
    exit;
}

$naam = strip_tags(trim($_POST["naam"] ?? ""));
$telefoon = strip_tags(trim($_POST["telefoon"] ?? ""));
$email = filter_var(trim($_POST["email"] ?? ""), FILTER_SANITIZE_EMAIL);
$categorie = strip_tags(trim($_POST["categorie"] ?? ""));
$bericht = trim($_POST["bericht"] ?? "");

$recipient = "info@delijsterij.nl";
$subjectSuffix = $categorie !== "" ? $categorie : "Contactaanvraag";
$subject = "Nieuw bericht van De Lijsterij: $subjectSuffix";

$email_content = "Naam: $naam\n";
$email_content .= "Telefoon: $telefoon\n";
$email_content .= "E-mail: $email\n";
$email_content .= "Categorie: $categorie\n\n";
$email_content .= "Bericht:\n$bericht\n";

$email_headers = "From: De Lijsterij <noreply@delijsterij.nl>\r\n";
if ($email !== "") {
    $email_headers .= "Reply-To: $email\r\n";
}

if (mail($recipient, $subject, $email_content, $email_headers)) {
    echo "<html><body style='font-family:sans-serif; text-align:center; padding-top:50px;'>";
    echo "<h1>Bedankt!</h1><p>Uw bericht is verzonden. Ik neem zo snel mogelijk contact met u op.</p>";
    echo "<a href='index.html#contact' style='color:#000; text-decoration:underline;'>Terug naar de website</a>";
    echo "</body></html>";
} else {
    echo "Oeps! Er ging iets mis bij het verzenden. Controleer of je hosting PHP mail ondersteunt.";
}
?>


