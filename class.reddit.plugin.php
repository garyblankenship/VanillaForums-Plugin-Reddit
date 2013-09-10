<?php if (!defined('APPLICATION')) exit();
/*
Copyright 2013 Vanilla Forums Inc.
This file is part of Garden.
Garden is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
Garden is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
You should have received a copy of the GNU General Public License along with Garden.  If not, see <http://www.gnu.org/licenses/>.
Contact Vanilla Forums Inc. at support [at] vanillaforums [dot] com
*/

// Define the plugin:
$PluginInfo['Reddit'] = array(
	'Name' => 'Reddit Social Connect',
   'Description' => 'Users may sign into your site using their Reddit account.',
   'Version' => '0.0.1',
   'RequiredApplications' => array('Vanilla' => '2.0.18'),
   'RequiredTheme' => FALSE,
   'RequiredPlugins' => FALSE,
	'MobileFriendly' => TRUE,
   'SettingsUrl' => '/dashboard/social/reddit',
   'SettingsPermission' => 'Garden.Settings.Manage',
   'HasLocale' => TRUE,
   'RegisterPermissions' => FALSE,
   'Author' => "Adrian Speyer",
   'AuthorEmail' => 'adrian@vanillaforums.com',
   'AuthorUrl' => 'http://www.vanillaforums.org/profile/adrian',
   'Hidden' => TRUE,
   'SocialConnect' => TRUE,
   'RequiresRegistration' => TRUE
);

//reddit info https://github.com/reddit/reddit/wiki/OAuth2

require_once PATH_LIBRARY.'/vendors/oauth/OAuth.php';

class RedditPlugin extends Gdn_Plugin {
   const ProviderKey = 'Reddit';
   public static $BaseApiUrl = 'https://ssl.reddit.com/api/v1/';

   protected $_AccessToken = NULL;
   
   /**
    * Gets/sets the current oauth access token.
    *
    * @param string $Token
    * @param string $Secret
    * @return OAuthToken
    */
   public function AccessToken($Token = NULL, $Secret = NULL) {
      if (!$this->IsConfigured()) 
         return FALSE;
      
      if (is_object($Token)) {
         $this->_AccessToken = $Token;
      } if ($Token !== NULL && $Secret !== NULL) {
         $this->_AccessToken = new OAuthToken($Token, $Secret);
      } elseif ($this->_AccessToken == NULL) {
         if ($Token)
            $this->_AccessToken = $this->GetOAuthToken($Token);
         elseif (Gdn::Session()->User) {
            $AccessToken = GetValueR(self::ProviderKey.'.AccessToken', Gdn::Session()->User->Attributes);
            
            if (is_array($AccessToken)) {
               $this->_AccessToken = new OAuthToken($AccessToken[0], $AccessToken[1]);
            }
         }
      }
      return $this->_AccessToken;
   }


   protected function _AuthorizeHref($Popup = FALSE) {
      $Url = Url('/entry/rdauthorize', TRUE);
      $UrlParts = explode('?', $Url);

      parse_str(GetValue(1, $UrlParts, ''), $Query);
      $Path = Gdn::Request()->Path();

      $Target = GetValue('Target', $_GET, $Path ? $Path : '/');
      if (ltrim($Target, '/') == 'entry/signin')
         $Target = '/';
      $Query['Target'] = $Target;

      if ($Popup)
         $Query['display'] = 'popup';
      $Result = $UrlParts[0].'?'.http_build_query($Query);

      return $Result;
   }

   /**
    *
    * @param Gdn_Controller $Sender
    */
   public function EntryController_SignIn_Handler($Sender, $Args) {
      if (isset($Sender->Data['Methods'])) {
         if (!$this->IsConfigured())
            return;

         $ImgSrc = Asset('/plugins/Reddit/design/reddit-signin.png');
         $ImgAlt = T('Sign In with Reddit');
            $SigninHref = $this->_AuthorizeHref();
            $PopupSigninHref = $this->_AuthorizeHref(TRUE);

            // Add the Reddit method to the controller.
            $RdMethod = array(
               'Name' => 'Reddit',
               'SignInHtml' => "<a id=\"RedditAuth\" href=\"$SigninHref\" class=\"PopupWindow\" popupHref=\"$PopupSigninHref\" popupHeight=\"400\" popupWidth=\"800\" rel=\"nofollow\"><img src=\"$ImgSrc\" alt=\"$ImgAlt\" /></a>");

         $Sender->Data['Methods'][] = $RdMethod;
      }
   }
   
   public function Base_SignInIcons_Handler($Sender, $Args) {
      if (!$this->IsConfigured())
			return;
			
		echo "\n".$this->_GetButton();
	}

   public function Base_BeforeSignInButton_Handler($Sender, $Args) {
      if (!$this->IsConfigured())
			return;
			
		echo "\n".$this->_GetButton();
	}
	
	public function Base_BeforeSignInLink_Handler($Sender) {
      if (!$this->IsConfigured())
			return;

		if (!Gdn::Session()->IsValid())
			echo "\n".Wrap($this->_GetButton(), 'li', array('class' => 'Connect RedditConnect'));
	}
   
   
	private function _GetButton() {      
      $ImgSrc = Asset('/plugins/Reddit/design/reddit-icon.png');
      $ImgAlt = T('Sign In with Reddit');
      $SigninHref = $this->_AuthorizeHref();
      $PopupSigninHref = $this->_AuthorizeHref(TRUE);
		return "<a id=\"RedditAuth\" href=\"$SigninHref\" class=\"PopupWindow\" title=\"$ImgAlt\" popupHref=\"$PopupSigninHref\" popupHeight=\"800\" popupWidth=\"800\" rel=\"nofollow\"><img src=\"$ImgSrc\" alt=\"$ImgAlt\" /></a>";
   }

	public function Authorize($Query = FALSE) {
      // Aquire the request token.
      $Consumer = new OAuthConsumer(C('Plugins.Reddit.ConsumerKey'), C('Plugins.Reddit.Secret'));
      $RedirectUri = $this->RedirectUri();
      if ($Query)
         $RedirectUri .= (strpos($RedirectUri, '?') === FALSE ? '?' : '&').$Query;

      $Params = array('oauth_callback' => $RedirectUri);
      
      $Url = 'https://ssl.reddit.com/api/v1/authorize';
      $Request = OAuthRequest::from_consumer_and_token($Consumer, NULL, 'POST', $Url, $Params);
      $SignatureMethod = new OAuthSignatureMethod_HMAC_SHA1();
      $Request->sign_request($SignatureMethod, $Consumer, null);

      $Curl = $this->_Curl($Request, $Params);
      $Response = curl_exec($Curl);
      if ($Response === FALSE) {
         $Response = curl_error($Curl);
      }

      $HttpCode = curl_getinfo($Curl, CURLINFO_HTTP_CODE);
      curl_close($Curl);

      if ($HttpCode == '200') {
         // Parse the reponse.
         $Data = OAuthUtil::parse_parameters($Response);

         if (!isset($Data['oauth_token']) || !isset($Data['oauth_token_secret'])) {
            $Response = T('The response was not in the correct format.');
         } else {
            // Save the token for later reference.
            $this->SetOAuthToken($Data['oauth_token'], $Data['oauth_token_secret'], 'request');

            // Redirect to Reddit's authorization page.
            $Url = "https://ssl.reddit.com/api/v1/access_token={$Data['oauth_token']}";
            Redirect($Url);
         }
      }

      // There was an error. Echo the error.
      echo $Response;
   }

   public function EntryController_rdauthorize_Create($Sender, $Dir = '') {
      $Query = ArrayTranslate($Sender->Request->Get(), array('display', 'Target'));
      $Query = http_build_query($Query);
      
      if ($Dir == 'profile') {
         // This is a profile connection.
         $this->RedirectUri(self::ProfileConnecUrl());
      }
      
      $this->Authorize($Query);
   }
   

   
   /**
    * 
    * @param ProfileController $Sender
    * @param type $UserReference
    * @param type $Username
    * @param type $oauth_token
    * @param type $oauth_verifier
    */
   public function ProfileController_RedditConnect_Create($Sender, $UserReference = '', $Username = '', $oauth_token = '', $oauth_verifier = '') {
      $Sender->Permission('Garden.SignIn.Allow');
      
      $Sender->GetUserInfo($UserReference, $Username, '', TRUE);
      
      $Sender->_SetBreadcrumbs(T('Connections'), '/profile/connections');
      
      // Get the access token.
      Trace('GetAccessToken()');
      $AccessToken = $this->GetAccessToken($oauth_token, $oauth_verifier);
      $this->AccessToken($AccessToken);
      
      // Get the profile.
      Trace('GetProfile()');
      $Profile = $this->GetProfile();
      
      // Save the authentication.
      Gdn::UserModel()->SaveAuthentication(array(
         'UserID' => $Sender->User->UserID,
         'Provider' => self::ProviderKey,
         'UniqueID' => $Profile['id']));
      
      // Save the information as attributes.
      $Attributes = array(
          'AccessToken' => array($AccessToken->key, $AccessToken->secret),
          'Profile' => $Profile
      );
      Gdn::UserModel()->SaveAttribute($Sender->User->UserID, self::ProviderKey, $Attributes);
      
      $this->EventArguments['Provider'] = self::ProviderKey;
      $this->EventArguments['User'] = $Sender->User;
      $this->FireEvent('AfterConnection');
      
      Redirect(UserUrl($Sender->User, '', 'connections'));
   }
   
   public function GetAccessToken($RequestToken, $Verifier) {
      if ((!$RequestToken || !$Verifier) && Gdn::Request()->Get('denied')) {
         throw new Gdn_UserException(T('Looks like you denied our request.'), 401);
      }
      
      // Get the request secret.
      $RequestToken = $this->GetOAuthToken($RequestToken);

      $Consumer = new OAuthConsumer(C('Plugins.Reddit.ConsumerKey'), C('Plugins.Reddit.Secret'));

      $Url = "https://ssl.reddit.com/api/v1/access_token?";
      $Params = array(
          'oauth_verifier' => $Verifier //GetValue('oauth_verifier', $_GET)
      );
      $Request = OAuthRequest::from_consumer_and_token($Consumer, $RequestToken, 'POST', $Url, $Params);

      $SignatureMethod = new OAuthSignatureMethod_HMAC_SHA1();
      $Request->sign_request($SignatureMethod, $Consumer, $RequestToken);
      $Post = $Request->to_postdata();

      $Curl = $this->_Curl($Request);
      $Response = curl_exec($Curl);
      if ($Response === FALSE) {
         $Response = curl_error($Curl);
      }
      $HttpCode = curl_getinfo($Curl, CURLINFO_HTTP_CODE);
      curl_close($Curl);

      if ($HttpCode == '200') {
         $Data = OAuthUtil::parse_parameters($Response);

         $AccessToken = new OAuthToken(GetValue('oauth_token', $Data), GetValue('oauth_token_secret', $Data));

         // Delete the request token.
         $this->DeleteOAuthToken($RequestToken);

      } else {
         // There was some sort of error.
         throw new Gdn_UserException('There was an error authenticating with Reddit. '.$Response, $HttpCode);
      }

      return $AccessToken;
   }

   /**
    *
    * @param Gdn_Controller $Sender
    * @param array $Args
    */
   public function Base_ConnectData_Handler($Sender, $Args) {
      if (GetValue(0, $Args) != 'Reddit')
         return;
      
      $Form = $Sender->Form; //new Gdn_Form();

      $RequestToken = GetValue('oauth_token', $_GET);
      $AccessToken = $Form->GetFormValue('AccessToken');
      
      if ($AccessToken) {
         $AccessToken = $this->GetOAuthToken($AccessToken);
         $this->AccessToken($AccessToken);
      }
      
      // Get the access token.
      if ($RequestToken && !$AccessToken) {
         // Get the request secret.
         $RequestToken = $this->GetOAuthToken($RequestToken);

         $Consumer = new OAuthConsumer(C('Plugins.Reddit.ConsumerKey'), C('Plugins.Reddit.Secret'));

         $Url = 'https://ssl.reddit.com/api/v1/access_token?';
         $Params = array(
             'oauth_verifier' => GetValue('oauth_verifier', $_GET)
         );
         $Request = OAuthRequest::from_consumer_and_token($Consumer, $RequestToken, 'POST', $Url, $Params);
         
         $SignatureMethod = new OAuthSignatureMethod_HMAC_SHA1();
         $Request->sign_request($SignatureMethod, $Consumer, $RequestToken);
         $Post = $Request->to_postdata();

         $Curl = $this->_Curl($Request);
         $Response = curl_exec($Curl);
         if ($Response === FALSE) {
            $Response = curl_error($Curl);
         }
         $HttpCode = curl_getinfo($Curl, CURLINFO_HTTP_CODE);
         curl_close($Curl);

         if ($HttpCode == '200') {
            $Data = OAuthUtil::parse_parameters($Response);

            $AccessToken = new OAuthToken(GetValue('oauth_token', $Data), GetValue('oauth_token_secret', $Data));
            
            // Save the access token to the database.
            $this->SetOAuthToken($AccessToken->key, $AccessToken->secret, 'access');
            $this->AccessToken($AccessToken->key, $AccessToken->secret);

            // Delete the request token.
            $this->DeleteOAuthToken($RequestToken);
            
         } else {
            // There was some sort of error.
            throw new Exception('There was an error authenticating with Reddit.', 400);
         }
         
         $NewToken = TRUE;
      }

      // Get the profile.
      try {
         $Profile = $this->GetProfile($AccessToken);
      } catch (Exception $Ex) {
         if (!isset($NewToken)) {
            // There was an error getting the profile, which probably means the saved access token is no longer valid. Try and reauthorize.
            if ($Sender->DeliveryType() == DELIVERY_TYPE_ALL) {
               Redirect($this->_AuthorizeHref());
            } else {
               $Sender->SetHeader('Content-type', 'application/json');
               $Sender->DeliveryMethod(DELIVERY_METHOD_JSON);
               $Sender->RedirectUrl = $this->_AuthorizeHref();
            }
         } else {
            throw $Ex;
         }
      }
      
      $ID = GetValue('id', $Profile);
      $Form->SetFormValue('UniqueID', $ID);
      $Form->SetFormValue('Provider', self::ProviderKey);
      $Form->SetFormValue('ProviderName', 'Reddit');
      $Form->SetValue('ConnectName', GetValue('screen_name', $Profile));
      $Form->SetFormValue('Name', GetValue('screen_name', $Profile));
      $Form->SetFormValue('FullName', GetValue('name', $Profile));
      $Form->SetFormValue('Photo', GetValue('profile_image_url', $Profile));
      $Form->AddHidden('AccessToken', $AccessToken->key);
      
      // Save some original data in the attributes of the connection for later API calls.
      $Attributes = array(self::ProviderKey => array(
          'AccessToken' => array($AccessToken->key, $AccessToken->secret),
          'Profile' => $Profile
      ));
      $Form->SetFormValue('Attributes', $Attributes);
      
      $Sender->SetData('Verified', TRUE);
   }
   
   public function Base_GetConnections_Handler($Sender, $Args) {
      $Profile = GetValueR('User.Attributes.'.self::ProviderKey.'.Profile', $Args);
      
      $Sender->Data["Connections"][self::ProviderKey] = array(
         'Icon' => $this->GetWebResource('icon.png', '/'),
         'Name' => 'Reddit',
         'ProviderKey' => self::ProviderKey,
         'ConnectUrl' => '/entry/rdauthorize/profile',
         'Profile' => array(
             'Name' => '@'.GetValue('screen_name', $Profile),
             'Photo' => GetValue('profile_image_url', $Profile)
             )
      );
   }

   public function API($Url, $Params = NULL, $Method = 'GET') {
      if (strpos($Url, '//') === FALSE)
         $Url = self::$BaseApiUrl.trim($Url, '/');
      $Consumer = new OAuthConsumer(C('Plugins.Reddit.ConsumerKey'), C('Plugins.Reddit.Secret'));
      
      if ($Method == 'POST') {
         $Post = $Params;
      } else
         $Post = NULL;

      $AccessToken = $this->AccessToken();

      
      $Request = OAuthRequest::from_consumer_and_token($Consumer, $AccessToken, $Method, $Url, $Params);
      
      $SignatureMethod = new OAuthSignatureMethod_HMAC_SHA1();
      $Request->sign_request($SignatureMethod, $Consumer, $AccessToken);
      
      $Curl = $this->_Curl($Request, $Post);
      curl_setopt($Curl, CURLINFO_HEADER_OUT, TRUE);
      $Response = curl_exec($Curl);
      $HttpCode = curl_getinfo($Curl, CURLINFO_HTTP_CODE);
      
      if ($Response == FALSE) {
         $Response = curl_error($Curl);
      }
      

      Trace(curl_getinfo($Curl, CURLINFO_HEADER_OUT));
      
      Trace($Response, 'Response');
      

      curl_close($Curl);

      Gdn::Controller()->SetJson('Response', $Response);
      if (strpos($Url, '.json') !== FALSE) {
         $Result = @json_decode($Response, TRUE) or $Response;
      } else {
         $Result = $Response;
      }
      
//      print_r($Result);
      
      if ($HttpCode == '200')
         return $Result;
      else {
         throw new Gdn_UserException(GetValueR('errors.0.message', $Result, $Response), $HttpCode);
      }
   }

   public function GetProfile() {
      $Profile = $this->API('/me.json?access_token=?', array('include_entities' => '0', 'skip_status' => '1'));
      return $Profile;
   }

   public function GetOAuthToken($Token) {
      $Row = Gdn::SQL()->GetWhere('UserAuthenticationToken', array('Token' => $Token, 'ProviderKey' => self::ProviderKey))->FirstRow(DATASET_TYPE_ARRAY);
      if ($Row) {
         return new OAuthToken($Row['Token'], $Row['TokenSecret']);
      } else {
         return NULL;
      }
   }

   public function IsConfigured() {
      $Result = C('Plugins.Reddit.ConsumerKey') && C('Plugins.Reddit.Secret');
      return $Result;
   }
   


   public function SetOAuthToken($Token, $Secret = NULL, $Type = 'request') {
      if (is_a($Token, 'OAuthToken')) {
         $Secret = $Token->secret;
         $Token = $Token->key;
      }

      // Insert the token.
      $Data = array(
                'Token' => $Token,
                'ProviderKey' => self::ProviderKey,
                'TokenSecret' => $Secret,
                'TokenType' => $Type,
                'Authorized' => FALSE,
                'Lifetime' => 60 * 5);
      Gdn::SQL()->Options('Ignore', TRUE)->Insert('UserAuthenticationToken', $Data);
   }

   public function DeleteOAuthToken($Token) {
      if (is_a($Token, 'OAuthToken')) {
         $Token = $Token->key;
      }
      
      Gdn::SQL()->Delete('UserAuthenticationToken', array('Token' => $Token, 'ProviderKey' => self::ProviderKey));
   }

   /**
    *
    * @param OAuthRequest $Request 
    */
   protected function _Curl($Request, $Post = NULL) {
      $C = curl_init();
      curl_setopt($C, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($C, CURLOPT_SSL_VERIFYPEER, FALSE);
      switch ($Request->get_normalized_http_method()) {
         case 'POST':
//            echo $Request->get_normalized_http_url();
//            echo "\n\n";
//            echo $Request->to_postdata();
            
            curl_setopt($C, CURLOPT_URL, $Request->get_normalized_http_url());
//            curl_setopt($C, CURLOPT_HTTPHEADER, array('Authorization' => $Request->to_header()));
            curl_setopt($C, CURLOPT_POST, TRUE);
            curl_setopt($C, CURLOPT_POSTFIELDS, $Request->to_postdata());
            break;
         default:
            curl_setopt($C, CURLOPT_URL, $Request->to_url());
      }
      return $C;
   }
   
   public static function ProfileConnecUrl() {
      return Url(UserUrl(Gdn::Session()->User, FALSE, 'redditconnect'), TRUE);
   }

   protected $_RedirectUri = NULL;

   public function RedirectUri($NewValue = NULL) {
      if ($NewValue !== NULL)
         $this->_RedirectUri = $NewValue;
      elseif ($this->_RedirectUri === NULL) {
         $RedirectUri = Url('/entry/connect/reddit', TRUE);
         $this->_RedirectUri = $RedirectUri;
      }

      return $this->_RedirectUri;
   }
   



   public function SocialController_Reddit_Create($Sender, $Args) {
   	  $Sender->Permission('Garden.Settings.Manage');
      if ($Sender->Form->IsPostBack()) {
         $Settings = array(
             'Plugins.Reddit.ConsumerKey' => $Sender->Form->GetFormValue('ConsumerKey'),
             'Plugins.Reddit.Secret' => $Sender->Form->GetFormValue('Secret'),
         );

         SaveToConfig($Settings);
         $Sender->InformMessage(T("Your settings have been saved."));

      } else {
         $Sender->Form->SetValue('ConsumerKey', C('Plugins.Reddit.ConsumerKey'));
         $Sender->Form->SetValue('Secret', C('Plugins.Reddit.Secret'));

      }

      $Sender->AddSideMenu('dashboard/social');
      $Sender->SetData('Title', T('Reddit Settings'));
      $Sender->Render('Settings', '', 'plugins/Reddit');
   }

   public function Setup() {
      // Make sure the user has curl.
      if (!function_exists('curl_exec')) {
         throw new Gdn_UserException('This plugin requires curl.');
      }

      // Save the Reddit provider type.
      Gdn::SQL()->Replace('UserAuthenticationProvider',
         array('AuthenticationSchemeAlias' => 'Reddit', 'URL' => '...', 'AssociationSecret' => '...', 'AssociationHashMethod' => '...'),
         array('AuthenticationKey' => self::ProviderKey));
   }
}

