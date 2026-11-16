<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiAuthController extends Controller
{
    private const DUMMY_PASSWORD_HASH = '$2y$10$0og.6Sf5XXLVj77iHH1nEOjmt6kWKT9PT.FxwARANL8CReTN2VkM2';
    private const MAX_TEACHER_DOCUMENTS = 5;
    private const MAX_TEACHER_DOCUMENT_SIZE = 10485760;
    private const MAX_TEACHER_DOCUMENT_TOTAL_SIZE = 31457280;

    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->call->model('UsersModel');
    }

    public function login()
    {
        $this->api->require_method('POST');
        $this->api->require_trusted_origin();
        $this->api->rate_limit('account-login', 10, 60);
        if (!$this->auth_is_configured()) {
            $this->api->respond_error('Authentication is not configured on this server.', 503);
            return;
        }

        $input = $this->api->body(false);
        $identifier = strtolower($this->text($input, ['identifier', 'email', 'username'], 255));
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        if ($identifier === '' || $password === '') {
            $this->api->respond_error('Email or username and password are required.', 400);
            return;
        }
        $user = $this->UsersModel->find_for_login($identifier);
        if (!$user) {
            password_verify($password, self::DUMMY_PASSWORD_HASH);
            $this->api->respond_error('Invalid email/username or password.', 401);
            return;
        }
        if (!password_verify($password, $user['password'])) {
            $this->api->respond_error('Invalid email/username or password.', 401);
            return;
        }
        if ($user['role'] === 'teacher') {
            $workflow = $this->teacher_workflow_status((int) $user['id']);
            if ((int) $user['is_active'] !== 1 || $workflow !== 'active') {
                $this->api->respond_error($this->teacher_login_message($workflow), 403);
                return;
            }
        } elseif ((int) $user['is_active'] !== 1) {
            $this->api->respond_error('Invalid email/username or password.', 401);
            return;
        }

        $teacher_review = $user['role'] === 'teacher'
            ? $this->teacher_review_status((int) $user['id'])
            : null;
        $welcome_message = null;
        if (
            $user['role'] === 'teacher'
            && (int) $user['is_active'] === 1
            && ($teacher_review['title'] ?? '') === 'Approved'
            && (!$user['last_login_at']
                || strtotime($user['last_login_at']) < strtotime($teacher_review['created_at']))
        ) {
            $teacher_profile = $this->db->table('teacher_profiles')
                ->select('gender')
                ->where('user_id', (int) $user['id'])
                ->get();
            $teacher_name = trim(implode(' ', array_filter([
                $user['first_name'] ?? '',
                $user['middle_name'] ?? '',
                $user['last_name'] ?? '',
            ])));
            $salutation = strtolower(trim((string) ($teacher_profile['gender'] ?? ''))) === 'female'
                ? 'Ma\'am '
                : (strtolower(trim((string) ($teacher_profile['gender'] ?? ''))) === 'male' ? 'Sir ' : '');
            $welcome_message = 'Welcome to KalingaLink, ' . $salutation . $teacher_name . '!';
        }
        $this->UsersModel->update_last_login($user['id']);
        $remember = filter_var($input['remember_me'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->respond_with_tokens($user, 200, 'Login successful.', $remember, $welcome_message);
    }

    public function google_start()
    {
        $this->api->rate_limit('google-login', 10, 60);
        $config = $this->google_oauth_config();
        if (!$config || !$this->auth_is_configured()) {
            $this->redirect_google_result('google_not_configured');
            return;
        }

        try {
            $state = bin2hex(random_bytes(32));
            $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        } catch (Throwable $error) {
            error_log('Google OAuth could not generate secure state.');
            $this->redirect_google_result('google_failed');
            return;
        }

        $cookie_options = [
            'expires' => time() + 600,
            'path' => '/api/auth/google',
            'secure' => $this->google_cookie_is_secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        setcookie('google_oauth_state', $state, $cookie_options);
        setcookie('google_oauth_verifier', $verifier, $cookie_options);

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $authorization_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid email',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);

        header('Cache-Control: no-store');
        header('Location: ' . $authorization_url, true, 302);
        exit;
    }

    public function google_callback()
    {
        $config = $this->google_oauth_config();
        if (!$config || !$this->auth_is_configured()) {
            $this->redirect_google_result('google_not_configured');
            return;
        }

        $state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
        $expected_state = is_string($_COOKIE['google_oauth_state'] ?? null)
            ? $_COOKIE['google_oauth_state']
            : '';
        $verifier = is_string($_COOKIE['google_oauth_verifier'] ?? null)
            ? $_COOKIE['google_oauth_verifier']
            : '';
        $this->clear_google_oauth_cookies();

        if ($state === '' || $expected_state === '' || $verifier === ''
            || !hash_equals($expected_state, $state)) {
            $this->redirect_google_result('google_failed');
            return;
        }

        if (($_GET['error'] ?? '') === 'access_denied') {
            $this->redirect_google_result('google_cancelled');
            return;
        }
        $code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
        if ($code === '') {
            $this->redirect_google_result('google_failed');
            return;
        }

        $tokens = $this->google_token_request($config, $code, $verifier);
        if (!$tokens) {
            $this->redirect_google_result('google_failed');
            return;
        }
        $identity = $this->google_identity_request($tokens['access_token']);
        if (!$identity || !is_string($identity['sub'] ?? null)
            || $identity['sub'] === ''
            || !is_string($identity['email'] ?? null)
            || !filter_var($identity['email'], FILTER_VALIDATE_EMAIL)
            || !in_array($identity['email_verified'] ?? false, [true, 'true', 1, '1'], true)) {
            $this->redirect_google_result('google_failed');
            return;
        }

        $user = $this->UsersModel->find_by_email(strtolower(trim($identity['email'])));
        if (!$user || !in_array($user['role'], ['admin', 'teacher', 'parent'], true)) {
            $this->redirect_google_result('google_unregistered');
            return;
        }

        if ((int) $user['is_active'] !== 1) {
            $this->redirect_google_result('google_inactive');
            return;
        }
        if ($user['role'] === 'teacher'
            && $this->teacher_workflow_status((int) $user['id']) !== 'active') {
            $this->redirect_google_result('google_inactive');
            return;
        }

        $this->UsersModel->update_last_login($user['id']);
        $session_tokens = $this->api->issue_tokens([
            'id' => (int) $user['id'],
            'role' => $user['role'],
            'scopes' => ['read', 'write'],
            'remember' => false,
        ]);
        $this->set_refresh_cookie($session_tokens['refresh_token'], false);
        header('Cache-Control: no-store');
        $this->redirect_google_result('google_success');
    }

    public function register_parent()
    {
        $this->api->require_method('POST');
        $this->api->require_trusted_origin();
        $this->api->rate_limit('parent-registration', 5, 3600);
        $this->create_account('parent', $this->api->body(false));
    }

    public function register_teacher()
    {
        $this->api->require_method('POST');
        $this->api->require_trusted_origin();
        $this->api->rate_limit('teacher-registration', 5, 3600);
        $this->create_account('teacher', $this->api->body(false));
    }

    public function refresh()
    {
        $this->api->require_method('POST');
        $this->api->require_trusted_origin();
        $this->api->rate_limit('token-refresh', 30, 60);
        if (!$this->auth_is_configured()) {
            $this->api->respond_error('Authentication is not configured on this server.', 503);
            return;
        }

        $refresh_token = (string) ($_COOKIE['refresh_token'] ?? '');
        $payload = $refresh_token !== '' ? $this->api->validate_jwt($refresh_token) : null;
        $user_id = filter_var($payload['sub'] ?? null, FILTER_VALIDATE_INT);
        if (!$payload || ($payload['type'] ?? '') !== 'refresh' || !$user_id) {
            $this->clear_refresh_cookie();
            $this->api->respond_error('Refresh session is missing or invalid.', 401);
            return;
        }

        $token_table = config_item('refresh_token_table');
        $hashed_token = hash_hmac('sha256', $refresh_token, (string) config_item('refresh_token_key'));
        $this->db->transaction();
        try {
            $token_statement = $this->db->raw(
                "SELECT id FROM {$token_table} WHERE token = ? AND user_id = ? AND expires_at > NOW() FOR UPDATE",
                [$hashed_token, $user_id]
            );
            if (!$token_statement->fetch(PDO::FETCH_ASSOC)) {
                $this->db->roll_back();
                $this->clear_refresh_cookie();
                $this->api->respond_error('Refresh session has expired or been revoked.', 401);
                return;
            }

            $user = $this->UsersModel->find_by_id($user_id);
            if (!$user || (int) $user['is_active'] !== 1
                || ($user['role'] === 'teacher'
                    && $this->teacher_workflow_status((int) $user['id']) !== 'active')) {
                $this->db->roll_back();
                $this->clear_refresh_cookie();
                $this->api->respond_error('Account is unavailable.', 401);
                return;
            }

            $this->db->table($token_table)
                ->where('token', $hashed_token)
                ->delete();
            $remember = ($payload['persistent'] ?? false) === true;
            $tokens = $this->api->issue_tokens([
                'id' => (int) $user['id'],
                'role' => $user['role'],
                'scopes' => ['read', 'write'],
                'remember' => $remember,
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $this->set_refresh_cookie($tokens['refresh_token'], $remember);
        unset($tokens['refresh_token']);
        $this->api->respond([
            'status' => true,
            'message' => 'Session refreshed.',
            'user' => $this->public_user($user),
            'tokens' => $tokens,
        ]);
    }

    public function logout()
    {
        $this->api->require_method('POST');
        $this->api->require_trusted_origin();
        $refresh_token = (string) ($_COOKIE['refresh_token'] ?? '');
        if ($refresh_token !== '' && $this->auth_is_configured()) {
            $this->api->revoke_refresh_token($refresh_token);
        }
        if ($this->auth_is_configured()) {
            $access_token = $this->api->get_bearer_token();
            $payload = $access_token
                ? $this->api->validate_jwt($access_token)
                : null;
            $user_id = filter_var($payload['sub'] ?? null, FILTER_VALIDATE_INT);
            $session_id = $payload['sid'] ?? null;
            if (
                $payload
                && ($payload['type'] ?? '') === 'access'
                && $user_id
                && is_string($session_id)
                && $session_id !== ''
            ) {
                $this->db->table(config_item('refresh_token_table'))
                    ->where('user_id', $user_id)
                    ->where('jti', $session_id)
                    ->delete();
            }
        }
        $this->clear_refresh_cookie();
        $this->api->respond(['status' => true, 'message' => 'Logged out.']);
    }

    public function me()
    {
        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();
        $user_id = filter_var($payload['sub'] ?? null, FILTER_VALIDATE_INT);
        if (!$user_id) {
            $this->api->respond_error('Invalid access token.', 401);
            return;
        }

        $user = $this->UsersModel->find_by_id($user_id);
        if (!$user || (int) $user['is_active'] !== 1) {
            $this->api->respond_error('Account is unavailable.', 401);
            return;
        }
        $response = ['status' => true, 'user' => $this->public_user($user)];
        if ($user['role'] === 'parent') {
            $response['parent_profile'] = $this->db->table('parent_profiles')
                ->where('user_id', $user_id)
                ->get() ?: null;
        }
        $this->api->respond($response);
    }

    private function create_account($role, array $input)
    {
        if (!$this->auth_is_configured()) {
            $this->api->respond_error('Authentication is not configured on this server.', 503);
            return;
        }

        $first_name = $this->text($input, ['first_name', 'firstName', 'parentFirstName'], 100);
        $middle_name = $this->text($input, ['middle_name', 'middleName', 'parentMiddleName'], 100);
        $last_name = $this->text($input, ['last_name', 'lastName', 'parentLastName'], 100);
        $username = strtolower($this->text($input, ['username'], 80));
        $email = strtolower($this->text($input, ['email', 'loginEmail', 'emailAddress', 'parentEmail'], 255));
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $confirmation = is_string($input['password_confirmation'] ?? $input['confirm_password'] ?? $input['confirmPassword'] ?? null)
            ? ($input['password_confirmation'] ?? $input['confirm_password'] ?? $input['confirmPassword'])
            : '';

        if ($first_name === '' || $last_name === '') {
            $this->api->respond_error('First and last name are required.', 400);
            return;
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username)) {
            $this->api->respond_error('Username must be 3–80 letters, numbers, periods, underscores, or hyphens.', 400);
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('A valid login email is required.', 400);
            return;
        }
        if (strlen($password) < 12 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $this->api->respond_error('Password must be at least 12 characters and include letters and numbers.', 400);
            return;
        }
        if (!is_string($confirmation) || $password !== $confirmation) {
            $this->api->respond_error('Passwords do not match.', 400);
            return;
        }

        $duplicate = $this->UsersModel->find_by_identity($email, $username);
        if ($duplicate) {
            $this->api->respond_error('That email or username is already registered.', 409);
            return;
        }

        $phone = $this->text($input, ['phone', 'contact_number', 'contactNumber', 'parentContact'], 30);
        $parent_birth_date = null;
        $parent_gender = '';
        $household_income = '';
        $preferred_language = '';
        $referral_source = '';
        if ($role === 'parent') {
            $parent_birth_date = $this->date_or_null($input['birth_date'] ?? $input['birthDate'] ?? $input['parentBirthDate'] ?? null);
            $parent_gender = $this->text($input, ['gender', 'parentGender'], 40);
            $household_income = $this->text($input, ['household_income', 'householdIncome'], 80);
            $preferred_language_choice = $this->text($input, ['preferred_language', 'preferredLanguage'], 80);
            $preferred_language_other = $this->text($input, ['preferred_language_other', 'preferredLanguageOther'], 70);
            $referral_source_choice = $this->text($input, ['referral_source', 'referralSource'], 120);
            $referral_source_other = $this->text($input, ['referral_source_other', 'referralSourceOther'], 110);

            if (!$parent_birth_date || $parent_birth_date > date('Y-m-d')) {
                $this->api->respond_error('Provide a valid parent date of birth that is not in the future.', 400);
                return;
            }
            if (!in_array($parent_gender, ['Male', 'Female', 'Prefer not to say'], true)) {
                $this->api->respond_error('Select a valid parent gender.', 400);
                return;
            }
            if ($phone === '' || !$this->is_philippine_mobile($phone)) {
                $this->api->respond_error('Enter a valid Philippine mobile number for the parent contact.', 400);
                return;
            }
            $allowed_income_ranges = [
                'Below ₱5,000',
                '₱5,000–₱9,999',
                '₱10,000–₱14,999',
                '₱15,000–₱19,999',
                '₱20,000–₱29,999',
                '₱30,000–₱49,999',
                '₱50,000 and above',
                'Prefer not to disclose',
            ];
            if ($household_income !== '' && !in_array($household_income, $allowed_income_ranges, true)) {
                $this->api->respond_error('Select a valid household income range.', 400);
                return;
            }
            if (!in_array($preferred_language_choice, ['', 'Filipino', 'English', 'Other'], true)) {
                $this->api->respond_error('Select a valid preferred language.', 400);
                return;
            }
            if (
                ($preferred_language_choice === 'Other' && $preferred_language_other === '')
                || ($preferred_language_choice !== 'Other' && $preferred_language_other !== '')
            ) {
                $this->api->respond_error('Specify a language only when Other is selected.', 400);
                return;
            }
            $preferred_language = $preferred_language_choice === 'Other'
                ? 'Other: ' . $preferred_language_other
                : $preferred_language_choice;

            $allowed_referral_sources = [
                'Barangay Official',
                'MSWDO',
                'CDC Teacher or Staff',
                'Family or Friends',
                'Social Media',
                'Community Announcement',
                'Referral',
                'Other',
            ];
            if ($referral_source_choice !== '' && !in_array($referral_source_choice, $allowed_referral_sources, true)) {
                $this->api->respond_error('Select a valid CDC referral source.', 400);
                return;
            }
            if (
                ($referral_source_choice === 'Other' && $referral_source_other === '')
                || ($referral_source_choice !== 'Other' && $referral_source_other !== '')
            ) {
                $this->api->respond_error('Specify a referral source only when Other is selected.', 400);
                return;
            }
            $referral_source = $referral_source_choice === 'Other'
                ? 'Other: ' . $referral_source_other
                : $referral_source_choice;
        } elseif ($phone !== '' && !$this->is_philippine_mobile($phone)) {
            $this->api->respond_error('Enter a valid Philippine mobile number.', 400);
            return;
        }
        $cdc_id = null;
        if ($role === 'teacher') {
            $cdc_id = filter_var($input['cdc_id'] ?? $input['cdcId'] ?? $input['cdc'] ?? null, FILTER_VALIDATE_INT);
            $center = $cdc_id
                ? $this->db->table('cdcs')->where('id', $cdc_id)->get()
                : false;
            if (!$center) {
                $this->api->respond_error('Select a registered Child Development Center.', 400);
                return;
            }
        }

        try {
            $photo = $role === 'parent' ? $this->validate_profile_photo($_FILES['parentPhoto'] ?? null) : null;
            $teacher_documents = $role === 'teacher'
                ? $this->collect_teacher_documents($_FILES['teacher_documents'] ?? null)
                : [];
        } catch (InvalidArgumentException $error) {
            $this->api->respond_error($error->getMessage(), 400);
            return;
        }
        $this->db->transaction();
        $stored_photo = null;
        $stored_teacher_documents = [];
        try {
            $this->db->table('users')->insert([
                'username' => $username,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'is_active' => $role === 'teacher' ? 0 : 1,
                'first_name' => $first_name,
                'middle_name' => $middle_name !== '' ? $middle_name : null,
                'last_name' => $last_name,
                'phone' => $phone !== '' ? $phone : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $user_id = $this->db->last_id();

            if ($photo) {
                $stored_photo = $this->store_profile_photo($photo, $user_id);
                $this->db->table('users')
                    ->where('id', $user_id)
                    ->update(['profile_photo' => $stored_photo['relative_path']]);
            }

            if ($role === 'parent') {
                $address_parts = array_filter([
                    $this->text($input, ['street', 'address', 'parentAddress'], 255),
                    $this->text($input, ['barangay', 'parentBarangay'], 120),
                    $this->text($input, ['municipality'], 120),
                    $this->text($input, ['province'], 120),
                ]);
                $this->db->table('parent_profiles')->insert([
                    'user_id' => $user_id,
                    'birth_date' => $parent_birth_date,
                    'gender' => $parent_gender !== '' ? $parent_gender : null,
                    'relationship' => $this->text($input, ['relationship'], 80) ?: null,
                    'address' => $address_parts ? implode(', ', $address_parts) : null,
                    'street' => $this->text($input, ['street'], 160) ?: null,
                    'barangay' => $this->text($input, ['barangay', 'parentBarangay'], 120) ?: null,
                    'contact_email' => strtolower($this->text($input, ['contact_email', 'parentEmail', 'emailAddress'], 255)) ?: null,
                    'suffix' => $this->text($input, ['suffix', 'parentSuffix'], 20) ?: null,
                    'municipality' => $this->text($input, ['municipality'], 120) ?: null,
                    'province' => $this->text($input, ['province'], 120) ?: null,
                    'postal_code' => $this->text($input, ['postal_code', 'postalCode'], 12) ?: null,
                    'household_income' => $household_income !== '' ? $household_income : null,
                    'preferred_language' => $preferred_language !== '' ? $preferred_language : null,
                    'referral_source' => $referral_source !== '' ? $referral_source : null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

            } else {
                $this->db->table('teacher_profiles')->insert([
                    'user_id' => $user_id,
                    'cdc_id' => null,
                    'requested_cdc_id' => $cdc_id,
                    'position' => $this->text($input, ['position'], 120) ?: null,
                    'hire_date' => $this->date_or_null($input['hire_date'] ?? $input['dateHired'] ?? null),
                    'employee_number' => $this->text($input, ['employee_number', 'employeeNumber'], 60) ?: null,
                    'contact_email' => strtolower($this->text($input, ['contact_email', 'contactEmail'], 255)) ?: null,
                    'employment_status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $this->db->table('teacher_account_verification')->insert([
                    'teacher_user_id' => (int) $user_id,
                    'status' => 'pending_review',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $admin_ids = $this->db->raw(
                    'SELECT id FROM users WHERE role = \'admin\' AND is_active = 1'
                )->fetchAll(PDO::FETCH_COLUMN);
                $this->record_teacher_review_event(
                    $user_id,
                    'Pending Review',
                    'A new teacher account is awaiting MSWDO Admin review.',
                    array_merge([$user_id], $admin_ids)
                );
                foreach ($teacher_documents as $document) {
                    $stored_document = $this->store_teacher_document($document, $user_id);
                    $stored_teacher_documents[] = $stored_document['absolute_path'];
                    $this->db->table('teacher_verification_documents')->insert([
                        'teacher_user_id' => (int) $user_id,
                        'original_name' => $document['name'],
                        'storage_path' => $stored_document['relative_path'],
                        'mime_type' => $document['mime'],
                        'file_size' => $document['size'],
                        'sha256' => $stored_document['sha256'],
                        'uploaded_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
            $profile_table = $role === 'parent' ? 'parent_profiles' : 'teacher_profiles';
            $profile = $this->db->table($profile_table)
                ->select('user_id')
                ->where('user_id', (int) $user_id)
                ->get();
            if (!$profile) {
                throw new RuntimeException('The role-specific account profile could not be saved.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            if ($stored_photo && is_file($stored_photo['absolute_path'])) {
                unlink($stored_photo['absolute_path']);
            }
            foreach ($stored_teacher_documents as $path) {
                if (is_file($path)) unlink($path);
            }
            if ($error instanceof InvalidArgumentException) {
                $this->api->respond_error($error->getMessage(), 400);
                return;
            }
            throw $error;
        }

        if ($role === 'teacher') {
            $this->api->respond([
                'status' => true,
                'message' => 'Teacher registration submitted for admin review. After approval, accept the emailed invitation to activate your account.',
                'data' => ['id' => (int) $user_id, 'verification_status' => 'pending_review'],
            ], 201);
            return;
        }

        $this->respond_with_tokens([
            'id' => $user_id,
            'username' => $username,
            'email' => $email,
            'role' => $role,
            'is_active' => 1,
            'first_name' => $first_name,
            'middle_name' => $middle_name,
            'last_name' => $last_name,
            'phone' => $phone,
            'profile_photo' => $stored_photo['relative_path'] ?? null,
        ], 201, 'Account created.');
    }

    private function respond_with_tokens(
        array $user,
        $status = 200,
        $message = 'Login successful.',
        $remember = false,
        $welcome_message = null
    )
    {
        $tokens = $this->api->issue_tokens([
            'id' => (int) $user['id'],
            'role' => $user['role'],
            'scopes' => ['read', 'write'],
            'remember' => $remember,
        ]);
        $this->set_refresh_cookie($tokens['refresh_token'], $remember);
        unset($tokens['refresh_token']);
        $public_user = $this->public_user($user);
        if (is_string($welcome_message) && $welcome_message !== '') {
            $public_user['welcome_message'] = $welcome_message;
        }
        $this->api->respond([
            'status' => true,
            'message' => $message,
            'user' => $public_user,
            'tokens' => $tokens,
        ], $status);
    }

    private function public_user(array $user)
    {
        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'first_name' => $user['first_name'] ?? '',
            'middle_name' => $user['middle_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'phone' => $user['phone'] ?? '',
            'profile_photo' => $user['profile_photo'] ?? null,
            'is_active' => (int) ($user['is_active'] ?? 1) === 1,
        ];
    }

    private function teacher_review_status($teacher_id)
    {
        return $this->db->raw(
            'SELECT title, message, created_at FROM notifications
             WHERE related_type = \'teacher_verification\' AND related_id = ?
             ORDER BY id DESC LIMIT 1',
            [(int) $teacher_id]
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function validate_profile_photo($file)
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK
            || !is_uploaded_file($file['tmp_name'] ?? '')
            || ($file['size'] ?? 0) <= 0
            || $file['size'] > 5242880) {
            throw new InvalidArgumentException('Profile photos must be no larger than 5 MB.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($extensions[$mime])) {
            throw new InvalidArgumentException('Profile photos must be JPG, PNG, or WEBP images.');
        }

        return [
            'tmp_name' => $file['tmp_name'],
            'mime' => $mime,
            'extension' => $extensions[$mime],
        ];
    }

    private function store_profile_photo(array $photo, $user_id)
    {
        $directory = ROOT_DIR . 'runtime/uploads/profile-photos/' . (int) $user_id;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the private profile photo directory.');
        }

        $stored_name = bin2hex(random_bytes(20)) . '.' . $photo['extension'];
        $absolute_path = $directory . DIRECTORY_SEPARATOR . $stored_name;
        if (!move_uploaded_file($photo['tmp_name'], $absolute_path)) {
            throw new RuntimeException('Could not store the profile photo.');
        }

        return [
            'absolute_path' => $absolute_path,
            'relative_path' => 'runtime/uploads/profile-photos/' . (int) $user_id . '/' . $stored_name,
        ];
    }

    private function collect_teacher_documents($upload)
    {
        if (!$upload || !isset($upload['name'])) return [];
        $names = is_array($upload['name']) ? $upload['name'] : [$upload['name']];
        $temporary_paths = is_array($upload['tmp_name']) ? $upload['tmp_name'] : [$upload['tmp_name']];
        $errors = is_array($upload['error']) ? $upload['error'] : [$upload['error']];
        $sizes = is_array($upload['size']) ? $upload['size'] : [$upload['size']];
        $documents = [];
        $extensions = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];

        foreach ($names as $index => $name) {
            $error = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) continue;
            $path = $temporary_paths[$index] ?? '';
            $size = (int) ($sizes[$index] ?? 0);
            if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($path)
                || $size < 1 || $size > self::MAX_TEACHER_DOCUMENT_SIZE) {
                throw new InvalidArgumentException('Each supporting document must be no larger than 10 MB.');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
            if (!isset($extensions[$mime])) {
                throw new InvalidArgumentException('Supporting documents must be PDF, JPG, or PNG files.');
            }
            $documents[] = [
                'tmp_name' => $path,
                'name' => mb_substr(basename((string) $name), 0, 255),
                'size' => $size,
                'mime' => $mime,
                'extension' => $extensions[$mime],
            ];
        }
        if (count($documents) > self::MAX_TEACHER_DOCUMENTS) {
            throw new InvalidArgumentException('Upload no more than five supporting documents.');
        }
        if (array_sum(array_column($documents, 'size')) > self::MAX_TEACHER_DOCUMENT_TOTAL_SIZE) {
            throw new InvalidArgumentException('Supporting documents must total no more than 30 MB.');
        }
        return $documents;
    }

    private function store_teacher_document(array $document, $user_id)
    {
        $relative_directory = 'runtime/uploads/teacher-verification/' . (int) $user_id;
        $directory = ROOT_DIR . $relative_directory;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the private teacher document directory.');
        }
        $stored_name = bin2hex(random_bytes(20)) . '.' . $document['extension'];
        $absolute_path = $directory . DIRECTORY_SEPARATOR . $stored_name;
        if (!move_uploaded_file($document['tmp_name'], $absolute_path)) {
            throw new RuntimeException('Could not store a teacher supporting document.');
        }
        $sha256 = hash_file('sha256', $absolute_path);
        if ($sha256 === false) {
            unlink($absolute_path);
            throw new RuntimeException('Could not verify a teacher supporting document.');
        }
        return [
            'absolute_path' => $absolute_path,
            'relative_path' => $relative_directory . '/' . $stored_name,
            'sha256' => $sha256,
        ];
    }

    private function set_refresh_cookie($token, $remember = true)
    {
        $same_site = config_item('refresh_cookie_samesite') ?: 'Lax';
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $cookie_path = config_item('refresh_cookie_path') ?: '/';
        if ($cookie_path !== '/api') {
            $this->clear_refresh_cookie_at_path('/api');
        }
        setcookie('refresh_token', $token, [
            'expires' => $remember
                ? time() + (int) config_item('refresh_token_expiration')
                : 0,
            'path' => $cookie_path,
            'secure' => $secure || $same_site === 'None' || config_item('refresh_cookie_secure'),
            'httponly' => true,
            'samesite' => $same_site,
        ]);
    }

    private function clear_refresh_cookie()
    {
        $cookie_path = config_item('refresh_cookie_path') ?: '/';
        $this->clear_refresh_cookie_at_path($cookie_path);
        if ($cookie_path !== '/api') {
            $this->clear_refresh_cookie_at_path('/api');
        }
    }

    private function clear_refresh_cookie_at_path($path)
    {
        $same_site = config_item('refresh_cookie_samesite') ?: 'Lax';
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie('refresh_token', '', [
            'expires' => time() - 3600,
            'path' => $path,
            'secure' => $secure || $same_site === 'None' || config_item('refresh_cookie_secure'),
            'httponly' => true,
            'samesite' => $same_site,
        ]);
    }

    private function auth_is_configured()
    {
        return (string) config_item('jwt_secret') !== ''
            && (string) config_item('refresh_token_key') !== '';
    }

    private function google_oauth_config()
    {
        $client_id = trim((string) getenv('GOOGLE_OAUTH_CLIENT_ID'));
        $client_secret = trim((string) getenv('GOOGLE_OAUTH_CLIENT_SECRET'));
        $redirect_uri = trim((string) getenv('GOOGLE_OAUTH_REDIRECT_URI'));
        $frontend_url = trim((string) getenv('GOOGLE_OAUTH_FRONTEND_URL'));
        if ($client_id === '' || $client_secret === '' || $redirect_uri === ''
            || !$this->is_http_url($redirect_uri) || !$this->is_http_url($frontend_url)) {
            return null;
        }
        return [
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri' => $redirect_uri,
            'frontend_url' => $frontend_url,
        ];
    }

    private function is_http_url($url)
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) return false;
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host']);
        if ($scheme === 'https') return true;
        return $scheme === 'http'
            && strtolower((string) getenv('APP_ENV')) !== 'production'
            && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function redirect_google_result($result)
    {
        $frontend_url = trim((string) getenv('GOOGLE_OAUTH_FRONTEND_URL'));
        if (!$this->is_http_url($frontend_url)) {
            $this->api->respond_error('Google sign-in is not configured on this server.', 503);
            return;
        }
        $destination = $frontend_url . (strpos($frontend_url, '?') === false ? '?' : '&')
            . 'google_result=' . rawurlencode($result);
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $destination, true, 302);
        exit;
    }

    private function google_cookie_is_secure()
    {
        $same_site = config_item('refresh_cookie_samesite') ?: 'Lax';
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
            || $same_site === 'None'
            || (bool) config_item('refresh_cookie_secure');
    }

    private function clear_google_oauth_cookies()
    {
        $options = [
            'expires' => time() - 3600,
            'path' => '/api/auth/google',
            'secure' => $this->google_cookie_is_secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        setcookie('google_oauth_state', '', $options);
        setcookie('google_oauth_verifier', '', $options);
    }

    private function google_token_request(array $config, $code, $verifier)
    {
        if (!function_exists('curl_init')) {
            error_log('Google OAuth requires the PHP cURL extension.');
            return null;
        }
        $handle = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $code,
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'redirect_uri' => $config['redirect_uri'],
                'grant_type' => 'authorization_code',
                'code_verifier' => $verifier,
            ], '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        $result = is_string($response) ? json_decode($response, true) : null;
        if ($status !== 200 || !is_array($result)
            || !is_string($result['access_token'] ?? null)
            || ($result['token_type'] ?? '') !== 'Bearer') {
            error_log('Google OAuth code exchange failed.');
            return null;
        }
        return $result;
    }

    private function google_identity_request($access_token)
    {
        if (!function_exists('curl_init')) return null;
        $handle = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
        curl_setopt_array($handle, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access_token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        $identity = is_string($response) ? json_decode($response, true) : null;
        if ($status !== 200 || !is_array($identity)) {
            error_log('Google OAuth identity verification failed.');
            return null;
        }
        return $identity;
    }

    private function record_teacher_review_event($teacher_id, $status, $message, array $recipient_ids)
    {
        $this->db->table('notifications')->insert([
            'title' => $status,
            'message' => $message,
            'audience' => 'single',
            'related_type' => 'teacher_verification',
            'related_id' => (int) $teacher_id,
            'related_module' => 'teacher_management',
            'notification_type' => 'System Update',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $notification_id = (int) $this->db->last_id();
        foreach (array_unique(array_map('intval', $recipient_ids)) as $recipient_id) {
            if ($recipient_id < 1) continue;
            $this->db->table('notification_recipients')->insert([
                'notification_id' => $notification_id,
                'user_id' => $recipient_id,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function teacher_workflow_status($teacher_id)
    {
        $workflow = $this->db->raw(
            'SELECT status FROM teacher_account_verification
             WHERE teacher_user_id = ? LIMIT 1',
            [(int) $teacher_id]
        )->fetch(PDO::FETCH_ASSOC);
        return $workflow['status'] ?? 'pending_review';
    }

    private function teacher_login_message($status)
    {
        $messages = [
            'pending_review' => 'Your teacher registration is awaiting admin review.',
            'under_review' => 'Your teacher registration is still under admin review.',
            'revision_required' => 'Your teacher account needs changes. Contact the MSWDO Admin for assistance.',
            'approved_awaiting_acceptance' => 'Your teacher account is approved. Accept the invitation sent to your email to activate it.',
            'rejected' => 'Your teacher registration was not approved. Contact the MSWDO Admin for assistance.',
            'active' => 'Your teacher account is unavailable.',
        ];
        return $messages[$status] ?? 'Your teacher account is unavailable.';
    }

    private function text(array $input, array $keys, $max_length)
    {
        foreach ($keys as $key) {
            if (is_string($input[$key] ?? null)) {
                return mb_substr(trim($input[$key]), 0, $max_length);
            }
        }
        return '';
    }

    private function date_or_null($value)
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function is_philippine_mobile($phone)
    {
        return preg_match('/^(?:09\d{9}|\+639\d{9})$/', $phone) === 1;
    }
}