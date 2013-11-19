<?php if(!defined('APPLICATION')) exit();
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
    'RequiredApplications' => array('Vanilla' => '2.1b2'),
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

// Reddit info: https://github.com/reddit/reddit/wiki/OAuth2

require_once PATH_LIBRARY . '/vendors/oauth/OAuth.php';

class RedditPlugin extends Gdn_Plugin {
   const ProviderKey = 'Reddit';

   protected $_AccessToken = NULL;

   public function AccessToken() {
      if(!$this->IsConfigured())
         return FALSE;

      if($this->_AccessToken === NULL) {
         if(Gdn::Session()->IsValid())
            $this->_AccessToken = GetValueR(self::ProviderKey . '.AccessToken', Gdn::Session()->User->Attributes);
         else
            $this->_AccessToken = FALSE;
      }

      return $this->_AccessToken;
   }

   public function Authorize($Query = FALSE) {
      $Uri = $this->AuthorizeUri($Query);
      Redirect($Uri);
   }

   public function API($Path, $Post = FALSE) {
      // Build the url.
      $Url = 'https://ssl.reddit.com/api/v1/authorize' . ltrim($Path, '/');

      $AccessToken = $this->AccessToken();
      if(!$AccessToken)
         throw new Gdn_UserException("You don't have a valid Reddit connection.");

      if(strpos($Url, '?') === false)
         $Url .= '?';
      else
         $Url .= '&';
      $Url .= 'access_token=' . urlencode($AccessToken);

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_HEADER, false);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($ch, CURLOPT_URL, $Url);

      if($Post !== false) {
         curl_setopt($ch, CURLOPT_POST, true);
         curl_setopt($ch, CURLOPT_POSTFIELDS, $Post);
         Trace("  POST $Url");
      } else {
         Trace("  GET  $Url");
      }

      $Response = curl_exec($ch);

      $HttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $ContentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
      curl_close($ch);

      Gdn::Controller()->SetJson('Type', $ContentType);

      if(strpos($ContentType, 'javascript') !== FALSE) {
         $Result = json_decode($Response, TRUE);

         if(isset($Result['error'])) {
            Gdn::Dispatcher()->PassData('RedditResponse', $Result);
            throw new Gdn_UserException($Result['error']['message']);
         }
      }
      else
         $Result = $Response;

      return $Result;
   }

   /**
    *
    * @param Gdn_Controller $Sender
    */
   public function EntryController_SignIn_Handler($Sender, $Args) {
      if(!$this->SocialSignIn())
         return;

      if(isset($Sender->Data['Methods'])) {
         $ImgSrc = Asset($this->GetPluginFolder(FALSE) . '/design/reddit-signin.png');
         $ImgAlt = T('Sign In with Reddit');

         $SigninHref = $this->AuthorizeUri();

         // Add the reddit method to the controller.
         $FbMethod = array(
             'Name' => self::ProviderKey,
             'SignInHtml' => "<a id=\"RedditAuth\" href=\"$SigninHref\" rel=\"nofollow\" ><img src=\"$ImgSrc\" alt=\"$ImgAlt\" /></a>");
      }
   }

   /**
    * Add 'Reddit' option to the row.
    */
   public function Base_DiscussionFormOptions_Handler($Sender, $Args) {
      if(!$this->AccessToken())
         return;

      $Options = & $Args['Options'];
   }

   public function Base_SignInIcons_Handler($Sender, $Args) {
      if(!$this->SocialSignIn())
         return;

      echo "\n" . $this->_GetButton();
   }

   public function Base_BeforeSignInButton_Handler($Sender, $Args) {
      if(!$this->SocialSignIn())
         return;

      echo "\n" . $this->_GetButton();
   }

   public function Base_BeforeSignInLink_Handler($Sender) {
      if(!$this->SocialSignIn())
         return;

      if(!Gdn::Session()->IsValid())
         echo "\n" . Wrap($this->_GetButton(), 'li', array('class' => 'Connect RedditConnect'));
   }

   public function Base_GetConnections_Handler($Sender, $Args) {
      $Profile = GetValueR('User.Attributes.' . self::ProviderKey . '.Profile', $Args);

      $Sender->Data["Connections"][self::ProviderKey] = array(
          'Icon' => $this->GetWebResource('icon.png', '/'),
          'Name' => 'Reddit',
          'ProviderKey' => self::ProviderKey,
          'ConnectUrl' => $this->AuthorizeUri(FALSE, self::ProfileConnectUrl()),
          'Profile' => array('Name' => GetValue('name', $Profile))
      );
   }

   /**
    * 
    * 
    * @param ProfileController $Sender
    * @param type $UserReference
    * @param type $Username
    * @param type $Code
    */
   public function ProfileController_RedditConnect_Create($Sender, $UserReference, $Username, $Code = FALSE) {
      $Sender->Permission('Garden.SignIn.Allow');

      $Sender->GetUserInfo($UserReference, $Username, '', TRUE);
      $Sender->_SetBreadcrumbs(T('Connections'), '/profile/connections');

      // Get the access token.
      $AccessToken = $this->GetAccessToken($Code, self::ProfileConnectUrl());

      // Get the profile.
      $Profile = $this->GetProfile($AccessToken);

      // Save the authentication.
      Gdn::UserModel()->SaveAuthentication(array(
          'UserID' => $Sender->User->UserID,
          'Provider' => self::ProviderKey,
          'UniqueID' => $Profile['id']));

      // Save the information as attributes.
      $Attributes = array(
          'AccessToken' => $AccessToken,
          'Profile' => $Profile
      );
      Gdn::UserModel()->SaveAttribute($Sender->User->UserID, self::ProviderKey, $Attributes);

      $this->EventArguments['Provider'] = self::ProviderKey;
      $this->EventArguments['User'] = $Sender->User;
      $this->FireEvent('AfterConnection');

      Redirect(UserUrl($Sender->User, '', 'connections'));
   }

   private function _GetButton() {
      $ImgSrc = Asset($this->GetPluginFolder(FALSE) . '/design/reddit-icon.png');
      $ImgAlt = T('Sign In with Reddit');
      $SigninHref = $this->AuthorizeUri();
      
      return "<a id=\"RedditAuth\" href=\"$SigninHref\" rel=\"nofollow\"><img src=\"$ImgSrc\" alt=\"$ImgAlt\" align=\"bottom\" /></a>";
   }

   public function SocialController_Reddit_Create($Sender, $Args) {
      $Sender->Permission('Garden.Settings.Manage');
      if($Sender->Form->IsPostBack()) {
         $Settings = array(
             'Plugins.Reddit.ClientID' => $Sender->Form->GetFormValue('ClientID'),
             'Plugins.Reddit.Secret' => $Sender->Form->GetFormValue('Secret'),
             'Plugins.Reddit.UseRedditNames' => $Sender->Form->GetFormValue('UseRedditNames'),
             'Plugins.Reddit.SocialSignIn' => $Sender->Form->GetFormValue('SocialSignIn'),
             'Garden.Registration.SendConnectEmail' => $Sender->Form->GetFormValue('SendConnectEmail'));

         SaveToConfig($Settings);
         $Sender->InformMessage(T("Your settings have been saved."));
      } else {
         $Sender->Form->SetValue('ClientID', C('Plugins.Reddit.ClientID'));
         $Sender->Form->SetValue('Secret', C('Plugins.Reddit.Secret'));
         $Sender->Form->SetValue('UseRedditNames', C('Plugins.Reddit.UseRedditNames'));
         $Sender->Form->SetValue('SendConnectEmail', C('Garden.Registration.SendConnectEmail', FALSE));
         $Sender->Form->SetValue('SocialSignIn', C('Plugins.Reddit.SocialSignIn', TRUE));
      }

      $Sender->AddSideMenu('dashboard/social');
      $Sender->SetData('Title', T('Reddit Settings'));
      $Sender->Render('Settings', '', 'plugins/Reddit');
   }

   /**
    *
    * @param Gdn_Controller $Sender
    * @param array $Args
    */
   public function EntryController_ConnectData_Handler($Sender, $Args) {
      if(GetValue(0, $Args) != 'reddit')
         return;

      if(isset($_GET['error'])) {
         // If the user denied the permission request at Reddit,
         // then redirect to the home page.
         if($_GET['error'] == "access_denied")
            Redirect('/');
         
         throw new Gdn_UserException(GetValue('error_description', $_GET, T('There was an error connecting to Reddit.')));
      }

      $AppID = C('Plugins.Reddit.ClientID');
      $Secret = C('Plugins.Reddit.Secret');
      $Code = GetValue('code', $_GET);

      $AccessToken = $Sender->Form->GetFormValue('AccessToken');
      
      // Get the access token.
      if(!$AccessToken && $Code) {
         // Exchange the token for an access token.
         $Code = urlencode($Code);

         $RedirectUri = $this->RedirectUri();
         $AccessToken = $this->GetAccessToken($Code, $RedirectUri);
         
         $NewToken = TRUE;
      }
      
      // Get the profile.
      try {
         $Profile = $this->GetProfile($AccessToken);
      } catch(Exception $Ex) {
         if(!isset($NewToken)) {
            // There was an error getting the profile, which probably means the saved access token is no longer valid. Try and reauthorize.
            if($Sender->DeliveryType() == DELIVERY_TYPE_ALL) {
               Redirect($this->AuthorizeUri());
            } else {
               $Sender->SetHeader('Content-type', 'application/json');
               $Sender->DeliveryMethod(DELIVERY_METHOD_JSON);
               $Sender->RedirectUrl = $this->AuthorizeUri();
            }
         } else {
            $Sender->Form->AddError('There was an error with the Reddit connection.');
         }
      }
      
      // If user has not verified their email at Reddit, then redirect to error controller.
      if(!GetValue('has_verified_email', $Profile))
         Redirect('/plugin/reddit/error/email_not_verified');
      
      $Form = $Sender->Form; //new Gdn_Form();
      $ID = GetValue('id', $Profile);
      $Form->SetFormValue('UniqueID', $ID);
      $Form->SetFormValue('Provider', self::ProviderKey);
      $Form->SetFormValue('ProviderName', 'Reddit');
      $Form->SetFormValue('FullName', GetValue('name', $Profile));
      // Email is not returned by Reddit API: $Form->SetFormValue('Email', GetValue('email', $Profile));
      $Form->AddHidden('AccessToken', $AccessToken);

      if(C('Plugins.Reddit.UseRedditNames')) {
         $Form->SetFormValue('Name', GetValue('name', $Profile));
         SaveToConfig(array(
             'Garden.User.ValidationRegex' => UserModel::USERNAME_REGEX_MIN,
             'Garden.User.ValidationLength' => '{3,50}',
             'Garden.Registration.NameUnique' => FALSE
                 ), '', FALSE);
      }

      // Save some original data in the attributes of the connection for later API calls.
      $Attributes = array();
      $Attributes[self::ProviderKey] = array(
          'AccessToken' => $AccessToken,
          'Profile' => $Profile
      );
      $Form->SetFormValue('Attributes', $Attributes);

      $Sender->SetData('Verified', TRUE);
   }
   
   public function PluginController_Reddit_Create($Sender, $Args) {
      // Handle error requests.
      if(strtolower($Sender->RequestArgs[0]) == "error") {
         // Email not verified error.
         if(strtolower($Sender->RequestArgs[1]) == "email_not_verified") {
            $Title = T('Reddit.Error.Authentication.Title', "Authentication Error");
            $Exception = T('Reddit.Error.Authentication.Exception', "You must verify your Reddit account's email address first.");
            $this->RenderBasicError($Sender, $Title, $Exception);
         }
      }
      
      // We are not using this controller for anything else, so redirect home.
      Redirect('/');
      return NULL;
   }
   
   private function RenderBasicError($Sender, $Title = 'Title', $Exception = 'Exception.') {
         $Sender->RemoveCssFile('admin.css');
         $Sender->AddCssFile('style.css');
         $Sender->MasterView = 'default';
         $Sender->CssClass = 'SplashMessage NoPanel';
         
         $Sender->SetData('Title', $Title);
         $Sender->SetData('Exception', $Exception);
         
         $Sender->Render('/home/error', '', 'dashboard');
   }
   
   protected function GetAccessToken($Code, $RedirectUri, $ThrowError = TRUE) {
      $Post = array(
               'client_id' => C('Plugins.Reddit.ClientID'),
               'client_secret' => C('Plugins.Reddit.Secret'),
               'grant_type' => 'authorization_code',
               'code' => $Code,
               'redirect_uri' => $RedirectUri
           );
      
      // Get the redirect URI.
      $Url = 'https://ssl.reddit.com/api/v1/access_token';
      $Curl = curl_init();
      curl_setopt($Curl, CURLOPT_RETURNTRANSFER, TRUE);
      curl_setopt($Curl, CURLOPT_SSL_VERIFYPEER, FALSE);
      curl_setopt($Curl, CURLOPT_USERPWD, $Post['client_id'] . ':' . $Post['client_secret']);
      curl_setopt($Curl, CURLOPT_POST, TRUE);
      curl_setopt($Curl, CURLOPT_POSTFIELDS, $Post);
      curl_setopt($Curl, CURLOPT_URL, $Url);
      $Contents = curl_exec($Curl);
      $Info = curl_getinfo($Curl);
      curl_close($Curl);
      
      $Tokens = json_decode($Contents, TRUE);
      
      if(GetValue('error', $Tokens))
         throw new Gdn_UserException('Reddit returned the following error: ' . GetValueR('error.message', $Tokens, 'Unknown error.'), 400);

      $AccessToken = GetValue('access_token', $Tokens);
      
      return $AccessToken;
   }

   public function GetProfile($AccessToken) {
      $Url = "https://oauth.reddit.com/api/v1/me";
      $Header = array('Authorization: Bearer ' . $AccessToken);
      
      $Curl = curl_init();
      curl_setopt($Curl, CURLOPT_URL, $Url);
      curl_setopt($Curl, CURLOPT_RETURNTRANSFER, TRUE);
      curl_setopt($Curl, CURLOPT_SSL_VERIFYPEER, FALSE);
      curl_setopt($Curl, CURLOPT_POST, FALSE);
      curl_setopt($Curl, CURLOPT_CUSTOMREQUEST, 'GET');
      curl_setopt($Curl, CURLOPT_HTTPHEADER, $Header);
      $Contents = curl_exec($Curl);
      // Debug Purposes: $Errors = curl_error($Curl); var_dump($Errors);
      curl_close($Curl);
      
      $Profile = json_decode($Contents, TRUE);
      return $Profile;
   }

   public function AuthorizeUri($Query = FALSE, $RedirectUri = FALSE) {
      $RandomState = md5(uniqid(rand(), true));
      $AppID = C('Plugins.Reddit.ClientID');

      if(!$RedirectUri)
         $RedirectUri = $this->RedirectUri();
      if($Query)
         $RedirectUri .= '&' . $Query;
      
      $MainGet = array(
            "duration" => "permanent", // 'temporary' or 'permanent'
            "response_type" => "code",
            "scope" => "identity",
            "state" => $RandomState,
            "client_id" => $AppID,
            "redirect_uri" => $RedirectUri
         );
      
      $SigninHref = "https://ssl.reddit.com/api/v1/authorize?" . http_build_query($MainGet);
      
      if($Query)
         $SigninHref .= '&' . $Query;
      
      return $SigninHref;
   }

   protected $_RedirectUri = NULL;

   public function RedirectUri($NewValue = NULL) {
      if($NewValue !== NULL) {
         $this->_RedirectUri = $NewValue;
      } elseif($this->_RedirectUri === NULL) {
         $RedirectUri = Url('/entry/connect/reddit', TRUE);
         if(strpos($RedirectUri, '=') !== FALSE) {
            $p = strrchr($RedirectUri, '=');
            $Uri = substr($RedirectUri, 0, -strlen($p));
            $p = urlencode(ltrim($p, '='));
            $RedirectUri = $Uri . '=' . $p;
         }

         $Path = Gdn::Request()->Path();

         $this->_RedirectUri = $RedirectUri;
      }

      return $this->_RedirectUri;
   }

   public static function ProfileConnectUrl() {
      return Url(UserUrl(Gdn::Session()->User, FALSE, 'redditconnect'), TRUE);
   }

   public function IsConfigured() {
      $AppID = C('Plugins.Reddit.ClientID');
      $Secret = C('Plugins.Reddit.Secret');
      
      if(!$AppID || !$Secret)
         return FALSE;
      
      return TRUE;
   }

   public function SocialSignIn() {
      return C('Plugins.Reddit.SocialSignIn', TRUE) && $this->IsConfigured();
   }

   public function Setup() {
      $Error = '';
      
      if(!function_exists('curl_init')) {
         $Error = ConcatSep("\n", $Error, 'This plugin requires curl.');
         
         throw new Gdn_UserException($Error, 400);
      }

      $this->Structure();
   }

   public function Structure() {
      // Save the reddit provider type.
      Gdn::SQL()->Replace('UserAuthenticationProvider', array(
                  'AuthenticationSchemeAlias' => 'reddit',
                  'URL' => '...',
                  'AssociationSecret' => '...',
                  'AssociationHashMethod' => '...'),
              array('AuthenticationKey' => self::ProviderKey), TRUE);
   }

   public function OnDisable() {
   }
}
