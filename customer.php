<?php

namespace LoyaltyProgram\Controller;
require_once(__DIR__ . '/customerauth.php');

use LoyaltyProgram\Model\Action;
use LoyaltyProgram\Model\Address;
use LoyaltyProgram\Model\Customer;
use LoyaltyProgram\Model\Model;
use LoyaltyProgram\Model\Referral;
use LoyaltyProgram\Model\ActionReferral;
use LoyaltyProgram\Model\AccessToken;
use LoyaltyProgram\Model\InvalidParamListException;
use LoyaltyProgram\Model\Organization;
use LoyaltyProgram\Model\Settings;
use LoyaltyProgram\Model\User;
use LoyaltyProgram\Utils\FacebookProfile;
use LoyaltyProgram\Utils\Generator;
use LoyaltyProgram\Utils\Mailer;
use LoyaltyProgram\Model\Transaction;

class CustomerController extends CustomerAuthController
{

    /**
     * Allows Customer to change his password.
     * Require auth.
     * @return bool
     * @throws \Exception
     */
    public function changePassword()
    {
        $this->_requireAuthorization('write', 'customer.personal');

        if ($this->customer->authorize($this->get('password_old'))) {
            $this->customer->password = $this->get('password_new');
            if (!$this->customer->save()){
                throw new InvalidParamListException($this->customer->errors);
            }
            return true;
        } else {
            throw new \Exception('Incorrect old password');
        }
    }

    public function changeEmail()
    {
        $this->_requireAuthorization('write', 'customer.personal');

        $this->customer->pending_email = $this->request->email;
        $this->customer->verification_token = Generator::generateVerificationToken();
        if (!$this->customer->save()){
            throw new InvalidParamListException($this->customer->errors);
        }
        $this->__sendChangeEmailNotification($this->customer);
        return true;
    }

    /**
     * todo techwriter method description
     * @throws \Exception
     */
    public static function checkProgramReady()
    {
        $setting = Settings::find_by_name_and_status(Settings::PROP_TRANSACTION_RATE, Settings::STATUS_ACTIVE);
        if (! $setting->value > 0)
            throw new \Exception('Before customer\'s registration - must be set association settings.');

        $org = Organization::getAssociation();
        if ( empty($org->title) )
            throw new \Exception('Before customer\'s registration - must be set association reward store settings.');
    }

    /**
     * Creates new Customer account and returns access token string
     * @return string|bool
     * @todo send confirmation email
     */
    public function create()
    {
        self::checkProgramReady();
        Customer::connection()->transaction();
        if ($customer = Customer::create_customer_web($this->request->customer)){
            Customer::connection()->commit();
            $token = AccessToken::createNewToken($customer->id);
            $this->__sendVerificationNotification($customer);
            return $token->auth_token;
        }
        return false;
    }


    /**
     * @return bool
     */
    public function getRewardsAppSms()
    {
        global $apiConfig;
        $cfg = $apiConfig['twilio'];
        $client = new \Services_Twilio($cfg['sid'], $cfg['token']);
        $message = file_get_contents('loyaltyprogram/resources/sms/getrewardsapp.txt');
        $message = str_replace('{download_url}', $apiConfig['mobile_sms_url'], $message);
        $sms = $client->account->sms_messages->create($cfg['from'], $this->request->phone, $message, array());
        return !empty($sms->sid);
    }

    public function signupWithFacebook()
    {
        $profile = FacebookProfile::get($this->request->access_token);
        if (!$profile || isset($profile->error)){
            throw new \Exception('Invalid access token');
        }
        if ($customer = Customer::find_by_facebook_id_and_status($profile->id, Customer::STATUS_CONFIRMED)){
            throw new \Exception('The account already exists. Please log in to the app with your Facebook account.');
        }
        Customer::connection()->transaction();
        if ($customer = Customer::create_facebook_customer_with_keytag($profile)){
            Customer::connection()->commit();
            $this->__sendRegistrationNotification($customer);
            $this->__sendNotificationForAllAdmin($customer);
            $token = AccessToken::createNewToken($customer->id);
            return $token->auth_token;
        }
        return false;
    }

    public function signinWithFacebook()
    {
        $profile = FacebookProfile::get($this->request->access_token);
        if (!$profile || isset($profile->error)){
            throw new \Exception('Invalid access token');
        }
        if ($customer = Customer::find_by_facebook_id_and_status($profile->id, Customer::STATUS_CONFIRMED)){
            $token = AccessToken::createNewToken($customer->id);
            return $token->auth_token;
        }
        if ($customer = Customer::find_by_email_and_status($profile->email, Customer::STATUS_CONFIRMED)){
            throw new \Exception('Account exists but not linked with Facebook');
        }
        throw new \Exception('The account does not exist. Please sign up to Loyalty Program with your Facebook account.');
    }

    /**
     * Performs e-mail verification. If succeed, register customer, which includes:
     *  - customer status is active
     *  - new keytag with active status is created and assigned to customer
     *  - generate password for customer's account and issue access token for session
     *  - if customer already active but requested change e-mail address
     * @return array
     * @throws \Exception
     */
    public function activate()
    {
        $customer = Customer::getByVerificationToken( $this->get('verification_token') );
        if (!$customer){
            throw new \Exception('Incorrect verification token value');
        }
        if ($customer->pending_email){
            $customer->email = $customer->pending_email;
            $customer->pending_email = null;
            if ($customer->status == Customer::STATUS_CONFIRMED){
                $customer->verification_token = null;
                $customer->save();
                $token = AccessToken::createNewToken($customer->id);
                $data = array(
                    'id' => $customer->id,
                    'keytag' => $customer->keytag->keytag_upca,
                    'token' => $token->auth_token,
                );
                return $data;
            }
        }
        if ($data = $customer->activate()){
            $this->__sendRegistrationNotification($customer);
            $this->__sendNotificationForAllAdmin($customer);
        } else {
            throw new \Exception('Account was not activated');
        }
        return $data;
    }

    public function activateByCode()
    {
        $this->_requireAuthorization();
        if (substr($this->customer->verification_token, 0, 5) != $this->request->confirmation_code){
            throw new \Exception('Incorrect confirmation code value');
        }
        if ($this->customer->pending_email){
            $this->customer->email = $this->customer->pending_email;
            $this->customer->pending_email = null;
            $this->customer->verification_token = null;
            if ($this->customer->status == Customer::STATUS_CONFIRMED){
                $this->customer->save();
                return true;
            }
        }
        if ($this->customer->activate(false)){
            $this->__sendRegistrationNotification($this->customer);
            $this->__sendNotificationForAllAdmin($this->customer);
        } else {
            throw new \Exception('Account was not activated');
        }
        return true;
    }

    public function updatePersonalData()
    {
        $this->_requireAuthorization('write', 'customer.personal');
        if (!is_array($this->request->customer)){
            throw new \Exception('Invalid parameter value', 100);
        }
        return $this->customer->updateProfile($this->request->customer);
    }

    public function updateAddress()
    {
        $this->_requireAuthorization('write', 'customer.personal');
        if (!is_array($this->request->address)){
            $this->request->address = array();
        }
        $this->customer->connection()->transaction();
        if ($res = $this->customer->updateAddress($this->request->address)){
            $this->customer->connection()->commit();
            return $res;
        }
        return false;
    }

    /**
     * @todo resend verification email
     */
    public function resendVerificationToken()
    {
        if ($customer = Customer::getByEmail($this->get('email'))){
            if ($customer->status != Customer::STATUS_INACTIVE){
                throw new \Exception('This operation available only for non-verified customers. Please use forgot password instead.');
            }
            return $this->__sendVerificationNotification($customer);
        }
        return false;
    }

    /**
     * @todo generate and send password to customer's email
     */
    public function forgotPassword()
    {
        if ($customer = Customer::getByEmailOrKeytag($this->get('email'))){
            if ($customer->status != Customer::STATUS_CONFIRMED){
                throw new \Exception('This operation available only for verified customers. Please use re-send verification token instead.');
            }
            $password = Generator::generatePassword();
            if ($this->__sendNewPasswordNotification($customer, $password)){
                $customer->update_attribute('password', $password);
                return $customer->email;
            }
        }
        return false;
    }

    /**
     * @return bool
     */
    public function setKeytagDelivery()
    {
        $this->_authenticate();
        return $this->customer->setKeytagDelivery($this->get('delivery_type'), $this->get('address'));
    }

    public function subscribe()
    {
        $this->_requireAuthorization();
        return $this->customer->subscribe($this->get('merchant_id'));
    }

    public function unsubscribe()
    {
        $this->_requireAuthorization();
        return $this->customer->unsubscribe($this->get('merchant_id'));
    }

    public function useScannedPromoCode()
    {
        $this->_requireAuthorization();
        if (empty($this->request->barcode))
            $this->fail('Promo code is not provided');
        /**
         * @var Action $promo
         */
        if ($promo = Action::find_by_qrcode_and_status($this->request->barcode, Action::STATUS_ACTIVE)){
            if (!$promo->type->is_scannable)
                $this->fail('This promotion is not applicable by scanning QR code');
            $points = $promo->apply($this->customer->id);

            $this->checkReferral($promo);
            return $points;
        }
        $this->fail('Promo does not exist');
    }

    public function check()
    {
        $this->_requireAuthorization();
        $this->checkReferral(Action::find(1499));
    }
    private function checkReferral(Action $promo)
    {
        if( $referral = Referral::find_existent($promo->id, $this->customer->email) ){
            if(! $referral_action = ActionReferral::find_existent($promo->id) )
            {
                $options['conditions'] = array('action_type_id = ? and is_base = ? and status = ? and organization_id = ?',
                    13, 1, Action::STATUS_ACTIVE, $promo->organization_id);
                if (! $referral_action =  Action::find($options))
                    return false;
            }
            $transaction = new Transaction();
            $transaction->type = Transaction::TRAN_TYPE_BEHAVIORAL;
            $transaction->customer_id = $referral->customer_id;
            $transaction->merchant_id = $referral_action->organization_id;
            $transaction->action_id = $referral_action->id;
            $transaction->trandatetime = date(Model::$format_datetime);
            $transaction->points = $referral_action->points;
            $transaction->fee = $referral_action->points * Settings::value_of(Settings::PROP_MERCHANT_LIABILITY_RATE);
            $transaction->fee_status = Transaction::FEE_STATUS_UNPAID;
            $transaction->status = Transaction::TRAN_STATUS_APPROVED;
            $transaction->points_status = Transaction::TRAN_STATUS_APPROVED;

            if ( !$transaction->save() ){
                throw new \Exception('Failed to create a behavioral Referral transaction');
            }

            $referral->status = Referral::STATUS_DELETED;
            if ( !$referral->save() ){
                throw new InvalidParamListException($referral->errors);
            }

            $customer = Customer::find($referral->customer_id);
            $this->__sendPointsReferralNotification($referral_action->points, $customer, $this->customer->email);
        }
    }

    public function createReferral()
    {
        $this->_requireAuthorization();

        if ( empty($this->request->email) )
            throw new \Exception('email must be enter');
        if ( empty($this->request->action_id) )
            throw new \Exception('Promotion id must be send');

        if ( $this->request->email == $this->customer->email )
            throw new \Exception('can not create referral for this email');

        if ( Referral::find_existent($this->request->action_id, $this->request->email) ){
            throw new \Exception('Referral for this promotion and email - exist');
        }

        $referral = new Referral();
        $referral->email = $this->request->email;
        $referral->customer_id = $this->customer->id;
        $referral->action_id = $this->request->action_id;

        if ( !$referral->save() ){
            throw new InvalidParamListException($referral->errors);
        }

        if( $customer = Customer::getByEmail($this->request->email) )
            $this->__sendCustomerReferralNotification($this->customer, $referral, $customer);
        else
            $this->__sendReferralNotification($this->customer, $referral);

        return true;
    }
    /**
     * Authorizes customer and returns access token
     * @return array
     * @throws \Exception
     */
    public function authorize()
    {
        $customer = Customer::getByEmail($this->get('login'));
        if (!$customer || !$customer->authorize($this->get('password'))) {
            throw new \Exception('Incorrect login or password', 101);
        }
        $token = AccessToken::createNewToken($customer->id);
        return array('token' => $token->auth_token);
    }

    /**
     * Allows for clients to check token validity if required
     */
    public function verifyToken()
    {
        $this->_requireAuthorization();
        return $this->customer->id;
    }

    /**
     * development-level function
     */
    public function delete(){
        if ($customer = Customer::getByEmail($this->get('email'))){
            if (strpos($customer->email, '@evogence.com') === false) {
                //throw new \Exception('Not allowed. Please use @evogence.com email address');
            }
            $customer->email = 'deleted'.time().'@evogence.com';
            $customer->status = Customer::STATUS_DELETED;
            return $customer->save(false);
        }
        return false;
    }

    // ----------------- Notifications -----------------

    /**
     * @todo refactoring (extract method)
     */
    private function __sendVerificationNotification(Customer $customer){
        global $apiConfig;
        $mail = new Mailer();
        $mail->addAddress($customer->email, $customer->firstname . ' ' . $customer->lastname);
        $mail->Subject = 'Getting Started with '.$apiConfig['application_title'];
        $html = file_get_contents('loyaltyprogram/resources/email/verification.html');
        $html = str_replace('{firstname}', $customer->firstname, $html);
        $html = str_replace('{confirmation_code}', substr($customer->verification_token, 0, 5), $html);
        $token = str_replace('{verification_token}', urlencode($customer->verification_token), $apiConfig['verification_token_url']);
        $html = str_replace('{verification_token_url}', $token, $html);
        $html = str_replace('{public_url}', $apiConfig['public_url'], $html);
        $html = str_replace('{application_title}', $apiConfig['application_title'], $html);
        $mail->msgHTML($html);
        if (!$mail->send()) {
            throw new \Exception('Error while send mail: ' . $mail->ErrorInfo);
        }
        return true;
    }

    /**
     * @todo refactoring (extract method)
     */
    private function __sendChangeEmailNotification(Customer $customer){
        global $apiConfig;
        $mail = new Mailer();
        $mail->addAddress($customer->pending_email, $customer->firstname . ' ' . $customer->lastname);
        $mail->Subject = 'Change E-mail address requested';
        $html = file_get_contents('loyaltyprogram/resources/email/verification.html');
        $html = str_replace('{firstname}', $customer->firstname, $html);
        $html = str_replace('{confirmation_code}', substr($customer->verification_token, 0, 5), $html);
        $token = str_replace('{verification_token}', urlencode($customer->verification_token), $apiConfig['verification_token_url']);
        $html = str_replace('{verification_token_url}', $token, $html);
        $html = str_replace('{public_url}', $apiConfig['public_url'], $html);
        $mail->msgHTML($html);
        if (!$mail->send()) {
            throw new \Exception('Error while send mail: ' . $mail->ErrorInfo);
        }
        return true;
    }

    /**
     * @todo refactoring (extract method)
     */
    private function __sendRegistrationNotification(Customer $customer){
        $mail = new Mailer();
        $mail->addAddress($customer->email, $customer->firstname . ' ' . $customer->lastname);
        $mail->Subject = 'Account activated';
        $html = file_get_contents('loyaltyprogram/resources/email/registration.html');
        global $apiConfig;
        $html = str_replace('{public_url}', $apiConfig['public_url'], $html);
        $mail->msgHTML($html);
        if (!$mail->send()) {
            throw new \Exception('Error while send mail: ' . $mail->ErrorInfo);
        }
        return true;
    }

    private function __sendNewPasswordNotification(Customer $customer, $password){
        $mail = new Mailer();
        $mail->addAddress($customer->email, $customer->firstname . ' ' . $customer->lastname);
        $mail->Subject = 'Forgot password notification';
        $html = file_get_contents('loyaltyprogram/resources/email/forgotpassword.html');
        $html = str_replace('{password}', $password, $html);
        global $apiConfig;
        $html = str_replace('{public_url}', $apiConfig['public_url'], $html);
        $mail->msgHTML($html);
        if (!$mail->send()) {
            throw new \Exception('Error while send mail: ' . $mail->ErrorInfo);
        }
        return true;
    }

    private function __sendNotificationForAllAdmin(Customer $customer)
    {
        $AllAdmin = User::find('all',array(
            'user_group_id' => 3, 'status' => User::STATUS_ACTIVE));
        foreach($AllAdmin as $admin){
            $this->__sendRegistrationNotification_Admin($admin, $customer);
        }
    }
    private function __sendRegistrationNotification_Admin(User $user, Customer $customer){
        $mail = new Mailer();
        $mail->addAddress($user->email, $user->firstname . ' ' . $user->lastname);
        $mail->Subject = 'Customer New Account';
        $html = file_get_contents('loyaltyprogram/resources/email/association/customer_registration.html');
        $html = str_replace('{email}', $customer->email, $html);
        $html = str_replace('{firstname}', $customer->firstname, $html);
        $html = str_replace('{lastname}', $customer->lastname, $html);
        $html = str_replace('{phone}', $customer->phone, $html);
	
        $address = Address::find('first', array('conditions'=>array('customer_id=? and status=?',
            $customer->id, User::STATUS_ACTIVE)));
        if($address)
            $html = str_replace('{zip}', $address->zip, $html);

        $custom = Customer::find($customer->id);
        $html = str_replace('{keytag}', $custom->keytag->keytag_upca, $html);
        $mail->msgHTML($html);
        if (!$mail->send()) {
            throw new \Exception('Error while send mail: ' . $mail->ErrorInfo);
        }
        return true;
    }

    private function __sendCustomerReferralNotification(Customer $customer, $referral, $customer_ref){
        $action = Action::find($referral->action_id);
        $merchant = Organization::find($action->organization_id);
        $action_info = $action->title . '<br>'
            . $action->brief_description
            . '<br><br> Validity period: ' . $action->date_from->format('m/d/Y H:i:s') . ' - ' . $action->date_to->format('m/d/Y H:i:s');
        if($action->coeff_modifier)  $action_info .= '<br> Coefficient: ' .  $action->coeff_modifier;
        if($action->points)  $action_info .= '<br>Points: ' . $action->points.'pts';

        $mail = new Mailer();
        $mail->addAddress($referral->email);
        $mail->Subject = 'Would like to share with you exciting promotion';
        $html = file_get_contents('loyaltyprogram/resources/email/referral_customer.html');
        global $apiConfig;
        $html = str_replace('{email_to}', $customer_ref->firstname . ' ' . $customer_ref->lastname, $html);
        $html = str_replace('{email_from}', $customer->firstname . ' ' . $customer->lastname, $html);
        $html = str_replace('{merchant}', $merchant->title, $html);
        $html = str_replace('{action_info}', $action_info, $html);
        $html = str_replace('{public_url}', $apiConfig['public_url'], $html);
        $mail->msgHTML($html);
        if (!$mail->send()) {
            throw new \Exception('Error while send mail: ' . $mail->ErrorInfo);
        }
        return true;
    }

    private function __sendReferralNotification($customer, $referral){
        $action = Action::find($referral->action_id);
        $merchant = Organization::find($action->organization_id);
        $action_info = $action->title . '<br>'
            . $action->brief_description
            . '<br><br> Validity period: ' . $action->date_from->format('m/d/Y H:i:s') . ' - ' . $action->date_to->format('m/d/Y H:i:s');
        if($action->coeff_modifier)  $action_info .= '<br> Coefficient: ' .  $action->coeff_modifier;
        if($action->points)  $action_info .= '<br>Points: ' . $action->points.'pts';

        $mail = new Mailer();
        $mail->addAddress($referral->email);
        $mail->Subject = 'Referral Notification';
        $html = file_get_contents('loyaltyprogram/resources/email/referral.html');
        global $apiConfig;
        $html = str_replace('{email_from}', $customer->firstname . ' ' . $customer->lastname, $html);
        $html = str_replace('{merchant}', $merchant->title, $html);
        $html = str_replace('{action_info}', $action_info, $html);
        $html = str_replace('{public_url}', $apiConfig['public_url'], $html);
        $mail->msgHTML($html);
        if (!$mail->send()) {
            throw new \Exception('Error while send mail: ' . $mail->ErrorInfo);
        }
        return true;
    }


    private function __sendPointsReferralNotification($points, Customer $customer, $email){
        $mail = new Mailer();
        $mail->addAddress($customer->email);
        $mail->Subject = 'You accrued points';
        $html = file_get_contents('loyaltyprogram/resources/email/referral_points.html');
        global $apiConfig;
        $html = str_replace('{email_to}', $customer->firstname . ' ' . $customer->lastname, $html);
        $html = str_replace('{points}', $points, $html);
        $html = str_replace('{email}', $email, $html);
        $html = str_replace('{public_url}', $apiConfig['public_url'], $html);
        $mail->msgHTML($html);
        if (!$mail->send()) {
            throw new \Exception('Error while send mail: ' . $mail->ErrorInfo);
        }
        return true;
    }
}