    <?php
    session_start();
    ini_set('display_errors', 1);
    error_reporting(E_ALL);

    require_once 'config.php';
    require_once 'vendor/autoload.php';

    use Google\Client;
    use Google\Service\Oauth2;

    /* ==============================
    Google Client Configuration
    ================================ */
    $client = new Client();
    $client->setClientId('555564439002-sea2jufonfcd8hnm96m754kd1nehgute.apps.googleusercontent.com');
    $client->setClientSecret('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');
    $client->setRedirectUri('http://localhost/roomrs/google-auth.php');
    $client->addScope(Oauth2::USERINFO_EMAIL);
    $client->addScope(Oauth2::USERINFO_PROFILE);
    $client->setPrompt('select_account');
    $client->setAccessType('online');

    /* ==============================
    STEP 1: Redirect to Google
    ================================ */
    if (!isset($_GET['code'])) {
        header('Location: ' . $client->createAuthUrl());
        exit();
    }

    /* ==============================
    STEP 2: Handle Google Callback
    ================================ */
    try {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

        if (isset($token['error'])) {
            throw new Exception($token['error_description'] ?? 'Token error');
        }

        $client->setAccessToken($token);
        $oauth2 = new Oauth2($client);
        $googleUser = $oauth2->userinfo->get();

        $fullname = trim($googleUser->name);
        $email    = trim($googleUser->email);
        $picture  = $googleUser->picture ?? null;

        if (!$email) {
            throw new Exception("Google did not return an email address.");
        }

        // Check if user exists by email
        $stmt = $conn->prepare("SELECT id, uname, password FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            // Existing user → update profile picture only
            $userId = $user['id'];

            $update = $conn->prepare("
                UPDATE users 
                SET profile_picture = ?, auth_provider = 'google', fullname = ?
                WHERE id = ?
            ");
            $update->bind_param("ssi", $picture, $fullname, $userId);
            $update->execute();

            $hashedPassword = $user['password']; // reuse existing hashed password
        } else {
            // New Google user → generate random password (hashed)
            $randomPassword = bin2hex(random_bytes(8)); // 16-character random password
            $hashedPassword = password_hash($randomPassword, PASSWORD_DEFAULT);

            // Generate a unique uname for the DB (can be email prefix or fallback)
            $baseUname = strtolower(preg_replace('/[^a-z0-9]/i', '', strstr($email, '@', true)));
            $uname = $baseUname ?: 'user';
            $check = $conn->prepare("SELECT id FROM users WHERE uname = ?");
            $check->bind_param("s", $uname);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;

            $suffix = 1;
            while ($exists) {
                $uname = $baseUname . $suffix;
                $check->bind_param("s", $uname);
                $check->execute();
                $exists = $check->get_result()->num_rows > 0;
                $suffix++;
            }

            $insert = $conn->prepare("
                INSERT INTO users (uname, fullname, email, password, profile_picture, auth_provider)
                VALUES (?, ?, ?, ?, ?, 'google')
            ");
            $insert->bind_param("sssss", $uname, $fullname, $email, $hashedPassword, $picture);
            $insert->execute();
            $userId = $insert->insert_id;
        }

        // Set session
        $_SESSION['user_id'] = $userId;
        $_SESSION['uname']   = $fullname; // use full name in session
        $_SESSION['email']   = $email;

        header("Location: dashboard.php");
        exit();

    } catch (Exception $e) {
        session_destroy();
        echo "<script>
            alert('Google login failed: " . addslashes($e->getMessage()) . "');
            window.location.href='login.php';
        </script>";
        exit();
    }