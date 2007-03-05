<?php

/**
 * Tests for the OpenID consumer.
 *
 * PHP versions 4 and 5
 *
 * LICENSE: See the COPYING file included in this distribution.
 *
 * @package OpenID
 * @author JanRain, Inc. <openid@janrain.com>
 * @copyright 2005 Janrain, Inc.
 * @license http://www.gnu.org/copyleft/lesser.html LGPL
 */

session_start();

require_once 'Auth/OpenID/CryptUtil.php';
require_once 'Services/Yadis/HTTPFetcher.php';
require_once 'Auth/OpenID/DiffieHellman.php';
require_once 'Auth/OpenID/FileStore.php';
require_once 'Auth/OpenID/KVForm.php';
require_once 'Auth/OpenID/Consumer.php';
require_once 'Auth/OpenID/Server.php';
require_once 'Auth/OpenID/Nonce.php';
require_once 'Auth/OpenID/HMACSHA1.php';
require_once 'Tests/Auth/OpenID/MemStore.php';
require_once 'PHPUnit.php';

class Auth_OpenID_TestConsumer extends Auth_OpenID_GenericConsumer {
    /**
     * Use a small (insecure) modulus for this test so that it runs quickly
     */
    /*
    function _createDiffieHellman()
    {
        return new Auth_OpenID_DiffieHellman('1235514290909');
    }
    */
}

global $_Auth_OpenID_assocs;
$_Auth_OpenID_assocs = array(
                            array('another 20-byte key.', 'Snarky'),
                            array(str_repeat("\x00", 20), 'Zeros'),
                            );

function Auth_OpenID_parse($qs)
{
    $result = array();
    $parts = explode("&", $qs);
    foreach ($parts as $pair) {
        list($key, $value) = explode("=", $pair, 2);
        assert(!array_key_exists($key, $result));
        $result[$key] = urldecode($value);
    }
    return $result;
}

function Auth_OpenID_associate($qs, $assoc_secret, $assoc_handle)
{
    $query_data = Auth_OpenID_parse($qs);

    assert($query_data['openid.mode'] == 'associate');
    assert($query_data['openid.assoc_type'] == 'HMAC-SHA1');

    $reply_dict = array(
                        'assoc_type' => 'HMAC-SHA1',
                        'assoc_handle' => $assoc_handle,
                        'expires_in' => '600',
                        );

    if (defined('Auth_OpenID_NO_MATH_SUPPORT')) {
        assert(count($query_data) == 2);
        $message = Auth_OpenID_Message::fromPostArgs($query_data);
        $session = Auth_OpenID_PlainTextServerSession::fromMessage($message);
    } else {
        assert((count($query_data) == 6) || (count($query_data) == 4));
        assert($query_data['openid.mode'] == 'associate');
        assert($query_data['openid.session_type'] == 'DH-SHA1');

        $message = Auth_OpenID_Message::fromPostArgs($query_data);

        $session = Auth_OpenID_DiffieHellmanSHA1ServerSession::fromMessage($message);
        $reply_dict['session_type'] = 'DH-SHA1';

    }

    $reply_dict = array_merge($reply_dict, $session->answer($assoc_secret));
    return Auth_OpenID_KVForm::fromArray($reply_dict);
}

class Auth_OpenID_TestFetcher extends Services_Yadis_HTTPFetcher {
    function Auth_OpenID_TestFetcher($user_url, $user_page,
                                     $assoc_secret, $assoc_handle)
    {
        $this->get_responses = array($user_url =>
                                     new Services_Yadis_HTTPResponse($user_url,
                                                                     200,
                                                                     array(),
                                                                     $user_page));
        $this->assoc_secret = $assoc_secret;
        $this->assoc_handle = $assoc_handle;
        $this->num_assocs = 0;
    }

    function response($url, $body)
    {
        if ($body === null) {
            return new Services_Yadis_HTTPResponse($url, 404, array(), 'Not found');
        } else {
            return new Services_Yadis_HTTPResponse($url, 200, array(), $body);
        }
    }

    function get($url)
    {
        if (array_key_exists($url, $this->get_responses)) {
            return $this->get_responses[$url];
        } else {
            return $this->response($url, null);
        }
    }

    function _checkAuth($url, $body)
    {
        $query_data = Auth_OpenID_parse($body);
        $expected = array(
                          'openid.mode' => 'check_authentication',
                          'openid.signed' => 'assoc_handle,sig,signed',
                          'openid.sig' => 'fake',
                          'openid.assoc_handle' => $this->assoc_handle,
                          );

        if ($query_data == $expected) {
            return new Services_Yadis_HTTPResponse($url, 200, array(), "is_valid:true\n");
        } else {
            return new Services_Yadis_HTTPResponse($url, 400, array(),
                                                "error:bad check_authentication query\n");
        }
    }

    function post($url, $body)
    {
        if (strpos($body, 'openid.mode=associate') !== false) {
            $response = Auth_OpenID_associate($body, $this->assoc_secret,
                                              $this->assoc_handle);
            $this->num_assocs++;
            return $this->response($url, $response);
        } elseif (strpos($body, 'openid.mode=check_authentication') !== false) {
            return $this->_checkAuth($url, $body);
        }

        return $this->response($url, null);
    }
}

global $_Auth_OpenID_user_page_pat;
$_Auth_OpenID_user_page_pat = "<html>
  <head>
    <title>A user page</title>
    %s
  </head>
  <body>
    blah blah
  </body>
</html>";

global $_Auth_OpenID_server_url;
$_Auth_OpenID_server_url = "http://server.example.com/";

global $_Auth_OpenID_consumer_url;
$_Auth_OpenID_consumer_url = "http://consumer.example.com/";

class Tests_Auth_OpenID_Consumer extends PHPUnit_TestCase {

    function _run(&$consumer, $user_url, $mode, $delegate_url,
                  &$fetcher, &$store, $immediate)
    {
        global $_Auth_OpenID_consumer_url,
            $_Auth_OpenID_server_url;

        $endpoint = new Auth_OpenID_ServiceEndpoint();
        $endpoint->claimed_id = $user_url;
        $endpoint->server_url = $_Auth_OpenID_server_url;
        $endpoint->local_id = $delegate_url;
        $endpoint->type_uris = array(Auth_OpenID_TYPE_1_1);

        $result = $consumer->begin($endpoint);

        $return_to = $_Auth_OpenID_consumer_url;
        $trust_root = $_Auth_OpenID_consumer_url;
        $redirect_url = $result->redirectURL($trust_root, $return_to,
                                             $immediate);

        $parsed = parse_url($redirect_url);
        $qs = $parsed['query'];
        $q = Auth_OpenID_parse($qs);
        $new_return_to = $q['openid.return_to'];
        unset($q['openid.return_to']);

        $expected = array(
                          'openid.mode' => $mode,
                          'openid.identity' => $delegate_url,
                          'openid.trust_root' => $trust_root
                          );

        if ($consumer->_use_assocs) {
            $expected['openid.assoc_handle'] = $fetcher->assoc_handle;
        }

        $this->assertEquals($expected, $q);
        $this->assertEquals(0, strpos($redirect_url, $_Auth_OpenID_server_url));
        $this->assertEquals(0, strpos($new_return_to, $return_to));

        $query = array(
                       $consumer->openid1_nonce_query_arg_name =>
                         $result->return_to_args[$consumer->openid1_nonce_query_arg_name],
                       'openid.mode'=> 'id_res',
                       'openid.return_to'=> $new_return_to,
                       'openid.identity'=> $delegate_url,
                       'openid.assoc_handle'=> $fetcher->assoc_handle,
                       );

        if (!$consumer->_use_assocs) {
            $query['openid.signed'] =
                'assoc_handle,mode,signed,identity';
            $query['openid.assoc_handle'] = $fetcher->assoc_handle;
            $query['openid.sig'] = 'fake';
        }

        $message = Auth_OpenID_Message::fromPostArgs($query);

        if ($consumer->_use_assocs) {
            $assoc = $store->getAssociation($_Auth_OpenID_server_url,
                                            $fetcher->assoc_handle);
            $message = $assoc->signMessage($message);
        }

        $result = $consumer->complete($message, $result->endpoint);

        $this->assertEquals(Auth_OpenID_SUCCESS, $result->status);
        $this->assertEquals($result->identity_url, $user_url);
    }

    function _test_success($user_url, $delegate_url, $links, $immediate = false)
    {
        global $_Auth_OpenID_filestore_base_dir,
            $_Auth_OpenID_server_url,
            $_Auth_OpenID_user_page_pat,
            $_Auth_OpenID_assocs;

        $store = new Tests_Auth_OpenID_MemStore();

        if ($immediate) {
            $mode = 'checkid_immediate';
        } else {
            $mode = 'checkid_setup';
        }

        $user_page = sprintf($_Auth_OpenID_user_page_pat, $links);
        $fetcher = new Auth_OpenID_TestFetcher($user_url, $user_page,
                                              $_Auth_OpenID_assocs[0][0],
                                              $_Auth_OpenID_assocs[0][1]);

        $consumer = new Auth_OpenID_TestConsumer($store);
        $consumer->fetcher =& $fetcher;

        $expected_num_assocs = 0;
        $this->assertEquals($expected_num_assocs, $fetcher->num_assocs);
        $this->_run($consumer, $user_url, $mode, $delegate_url,
                    $fetcher, $store, $immediate);

        if ($consumer->_use_assocs) {
            $expected_num_assocs += 1;
        }

        $this->assertEquals($expected_num_assocs, $fetcher->num_assocs);

        // Test that doing it again uses the existing association
        $this->_run($consumer, $user_url, $mode, $delegate_url,
                    $fetcher, $store, $immediate);

        $this->assertEquals($expected_num_assocs, $fetcher->num_assocs);

        // Another association is created if we remove the existing one
        $store->removeAssociation($_Auth_OpenID_server_url,
                                  $fetcher->assoc_handle);

        $this->_run($consumer, $user_url, $mode, $delegate_url,
                    $fetcher, $store, $immediate);

        if ($consumer->_use_assocs) {
            $expected_num_assocs += 1;
        }

        $this->assertEquals($expected_num_assocs, $fetcher->num_assocs);

        // Test that doing it again uses the existing association
        $this->_run($consumer, $user_url, $mode, $delegate_url,
                    $fetcher, $store, $immediate);

        $this->assertEquals($expected_num_assocs, $fetcher->num_assocs);
    }

    function test_success()
    {
        global $_Auth_OpenID_server_url;

        $user_url = 'http://www.example.com/user.html';
        $links = sprintf('<link rel="openid.server" href="%s" />',
                         $_Auth_OpenID_server_url);

        $delegate_url = 'http://consumer.example.com/user';
        $delegate_links = sprintf('<link rel="openid.server" href="%s" />'.
                                  '<link rel="openid.delegate" href="%s" />',
                                  $_Auth_OpenID_server_url, $delegate_url);

        $this->_test_success($user_url, $user_url, $links);
        $this->_test_success($user_url, $user_url, $links, true);
        $this->_test_success($user_url, $delegate_url, $delegate_links);
        $this->_test_success($user_url, $delegate_url, $delegate_links, true);
    }
}

class _TestIdRes extends PHPUnit_TestCase {
    var $consumer_class = 'Auth_OpenID_GenericConsumer';

    function setUp()
    {
        $this->store = new Tests_Auth_OpenID_MemStore();
        $cl = $this->consumer_class;
        $this->consumer = new $cl($this->store);
        $this->return_to = "http://some.host/path";
        $this->endpoint = new Auth_OpenID_ServiceEndpoint();

        $this->server_id = "sirod";
        $this->server_url = "serlie";
        $this->consumer_id = "consu";

        $this->endpoint->claimed_id = $this->consumer_id;
        $this->endpoint->server_url = $this->server_url;
        $this->endpoint->local_id = $this->server_id;
        $this->endpoint->type_uris = array(Auth_OpenID_TYPE_1_1);
    }
}

class Tests_Auth_OpenID_Consumer_TestSetupNeeded extends _TestIdRes {
    function failUnlessSetupNeeded($expected_setup_url, $message)
    {
        if ($this->consumer._checkSetupNeeded($message)) {
            $this->assertEquals($expected_setup_url,
                                $message->getArg(Auth_OpenID_OPENID_NS,
                                                 'user_setup_url'));
        } else {
            $this->fail("Expected to find an immediate-mode response");
        }
    }

    function test_setupNeededOpenID1()
    {
        // The minimum conditions necessary to trigger Setup Needed
        $setup_url = 'http://unittest/setup-here';
        $message = Auth_OpenID_Message::fromPostArgs(array(
            'opaenid.mode' => 'id_res',
            'openid.user_setup_url' => $setup_url
            ));
        $this->assertTrue($message->isOpenID1());
        $this->failUnlessSetupNeeded($setup_url, $message);
    }

    function test_setupNeededOpenID1_extra()
    {
        // Extra stuff along with setup_url still trigger Setup Needed
        $setup_url = 'http://unittest/setup-here';
        $message = Auth_OpenID_Message::fromPostArgs(array(
            'openid.mode' => 'id_res',
            'openid.user_setup_url' => $setup_url,
            'openid.identity' => 'bogus'
            ));
        $this->assertTrue($message->isOpenID1());
        $this->failUnlessSetupNeeded($setup_url, $message);
    }

    function test_noSetupNeededOpenID1()
    {
        // When the user_setup_url is missing on an OpenID 1 message,
        // we assume that it's not a cancel response to
        // checkid_immediate
        $message = Auth_OpenID_Message::fromOpenIDArgs(array('mode' => 'id_res'));
        $this->assertTrue($message->isOpenID1());

        // No SetupNeededError raised
        $this->consumer->_checkSetupNeeded($message);
    }

    function test_setupNeededOpenID2()
    {
        $message = Auth_OpenID_Message::fromOpenIDArgs(array(
            'mode' => 'setup_needed',
            'ns' => Auth_OpenID_OPENID2_NS
            ));
        $this->assertTrue($message->isOpenID2());
        $response = $this->consumer->complete($message, null, null);
        $this->assertEquals('setup_needed', $response->status);
        $this->assertEquals(null, $response->setup_url);
    }

    function test_setupNeededDoesntWorkForOpenID1()
    {
        $message = Auth_OpenID_Message::fromOpenIDArgs(array(
                     'mode' => 'setup_needed'));

        $this->assertFalse($this->consumer._checkSetupNeeded($message));

        $response = $this->consumer->complete($message, null, null);
        $this->assertEquals('failure', $response->status);
        $this->assertTrue(strpos($response->message, 'Invalid openid.mode') === 0);
    }

    function test_noSetupNeededOpenID2()
    {
        $message = Auth_OpenID_Message::fromOpenIDArgs(array(
            'mode' => 'id_res',
            'game' => 'puerto_rico',
            'ns' => Auth_OpenID_OPENID2_NS
            ));
        $this->assertTrue($message->isOpenID2());

        $this->assertFalse($this->consumer._checkSetupNeeded($message));
    }
}

define('E_CHECK_AUTH_HAPPENED', 'checkauth occurred');
define('E_MOCK_FETCHER_EXCEPTION', 'mock fetcher exception');
define('E_ASSERTION_ERROR', 'assertion error');

class _CheckAuthDetectingConsumer extends Auth_OpenID_GenericConsumer {
    function _verifyDiscoveryResults($message, $endpoint)
    {
        return $endpoint;
    }

    function _idResCheckNonce($message, $endpoint)
    {
        return true;
    }

    function _checkAuth($query, $server_url)
    {
        __raiseError(E_CHECK_AUTH_HAPPENED);
    }
}

global $GOODSIG;
$GOODSIG = "[A Good Signature]";

class GoodAssociation {
    var $expiresIn = 3600;
    var $handle = "-blah-";

    function getExpiresIn()
    {
        return $this->expiresIn;
    }

    function checkMessageSignature($message)
    {
        global $GOODSIG;
        return $message->getArg(Auth_OpenID_OPENID_NS, 'sig') == $GOODSIG;
    }
}

class GoodAssocStore extends Tests_Auth_OpenID_MemStore {
    function getAssociation($server_url, $handle = null)
    {
        return new GoodAssociation();
    }
}

class TestIdResCheckSignature extends _TestIdRes {
    function setUp()
    {
        global $GOODSIG;

        parent::setUp();
        $this->assoc = new GoodAssociation();
        $this->assoc->handle = "{not_dumb}";
        $this->store->storeAssociation($this->endpoint->server_url, $this->assoc);

        $this->message = Auth_OpenID_Message::fromPostArgs(array(
            'openid.mode'=> 'id_res',
            'openid.identity'=> '=example',
            'openid.sig'=> $GOODSIG,
            'openid.assoc_handle'=> $this->assoc->handle,
            'openid.signed'=> 'mode,identity,assoc_handle,signed',
            'frobboz'=> 'banzit'));
    }

    function test_sign()
    {
        // assoc_handle to assoc with good sig
        $this->consumer->_idResCheckSignature($this->message,
                                              $this->endpoint->server_url);
    }

    function test_signFailsWithBadSig()
    {
        $this->message.setArg(Auth_OpenID_OPENID_NS, 'sig', 'BAD SIGNATURE');
        $result = $this->consumer->_idResCheckSignature($this->message, $this->endpoint->server_url);
        $this->assertTrue(is_a($result, 'Auth_OpenID_FailureResponse'));
    }
}

class StatelessConsumer1 extends Auth_OpenID_GenericConsumer {
    function _processCheckAuthResponse($response, $server_url)
    {
        return true;
    }

    function _makeKVPost($args, $server_url)
    {
        return array();
    }
}

class Tests_Auth_OpenID_Stateless1 extends _TestIdRes {
    var $consumer_class = "StatelessConsumer1";

    function setUp()
    {
        global $GOODSIG;

        parent::setUp();
        $this->assoc = new GoodAssociation();
        $this->assoc->handle = "{not_dumb}";
        $this->store->storeAssociation($this->endpoint->server_url, $this->assoc);

        $this->message = Auth_OpenID_Message::fromPostArgs(array(
            'openid.mode'=> 'id_res',
            'openid.identity'=> '=example',
            'openid.sig'=> $GOODSIG,
            'openid.assoc_handle'=> $this->assoc->handle,
            'openid.signed'=> 'mode,identity,assoc_handle,signed',
            'frobboz'=> 'banzit'));
    }

    function test_stateless()
    {
        // assoc_handle missing assoc, consumer._checkAuth returns
        // goodthings
        $this->message->setArg(Auth_OpenID_OPENID_NS, "assoc_handle", "dumbHandle");
        $this->consumer->_idResCheckSignature($this->message,
                                              $this->endpoint->server_url);
    }
}

class StatelessConsumer2 extends Auth_OpenID_GenericConsumer {
    function _checkAuth($_, $__)
    {
        return false;
    }
}

class Tests_Auth_OpenID_Stateless2 extends _TestIdRes {
    var $consumer_class = "StatelessConsumer2";

    function setUp()
    {
        global $GOODSIG;

        parent::setUp();
        $this->assoc = new GoodAssociation();
        $this->assoc->handle = "{not_dumb}";
        $this->store->storeAssociation($this->endpoint->server_url, $this->assoc);

        $this->message = Auth_OpenID_Message::fromPostArgs(array(
            'openid.mode'=> 'id_res',
            'openid.identity'=> '=example',
            'openid.sig'=> $GOODSIG,
            'openid.assoc_handle'=> $this->assoc->handle,
            'openid.signed'=> 'mode,identity,assoc_handle,signed',
            'frobboz'=> 'banzit'));
    }

    function test_statelessRaisesError()
    {
        // assoc_handle missing assoc, consumer._checkAuth returns
        // goodthings
        $this->message->setArg(Auth_OpenID_OPENID_NS, "assoc_handle",
                               "dumbHandle");
        $result = $this->consumer->_idResCheckSignature($this->message,
                                                        $this->endpoint->server_url);
        $this->assertTrue(is_a($result, 'Auth_OpenID_FailureResponse'));
    }
}

class Tests_Auth_OpenID_Consumer_CheckNonceTest extends _TestIdRes {
    function setUp()
    {
        parent::setUp();
        $this->consumer->openid1_nonce_query_arg_name = 'nonce';
    }

    function test_openid1Success()
    {
        // use consumer-generated nonce
        $this->return_to = sprintf('http://rt.unittest/?nonce=%s',
                                   Auth_OpenID_mkNonce());
        $this->response = Auth_OpenID_Message::fromOpenIDArgs(
                            array('return_to' => $this->return_to));

        $result = $this->consumer->_idResCheckNonce($this->response, $this->endpoint);
        $this->assertFalse(is_a($result, 'Auth_OpenID_FailureResponse'));
    }

    function test_openid1Missing()
    {
        // use consumer-generated nonce
        $this->response = Auth_OpenID_Message::fromOpenIDArgs(array());
        $n = $this->consumer->_idResGetNonceOpenID1($this->response, $this->endpoint);
        $this->assertTrue($n === null);
    }

    function test_consumerNonceOpenID2()
    {
        // OpenID 2 does not use consumer-generated nonce
        $this->return_to = sprintf('http://rt.unittest/?nonce=%s',
                                   Auth_OpenID_mkNonce());
        $this->response = Auth_OpenID_Message::fromOpenIDArgs(
                            array('return_to' => $this->return_to,
                                  'ns' => Auth_OpenID_OPENID2_NS));
        $result = $this->consumer->_idResCheckNonce($this->response, $this->endpoint);
        $this->assertTrue(is_a($result, 'Auth_OpenID_FailureResponse'));
    }

    function test_serverNonce()
    {
        // use server-generated nonce
        $this->response = Auth_OpenID_Message::fromOpenIDArgs(
                            array('ns' => Auth_OpenID_OPENID2_NS,
                                  'response_nonce' => Auth_OpenID_mkNonce()));
        $this->consumer->_idResCheckNonce($this->response, $this->endpoint);
    }

    function test_serverNonceOpenID1()
    {
        // OpenID 1 does not use server-generated nonce
        $this->response = Auth_OpenID_Message::fromOpenIDArgs(
        array('ns' => Auth_OpenID_OPENID1_NS,
             'return_to'=> 'http://return.to/',
              'response_nonce'=> Auth_OpenID_mkNonce()));
        $result = $this->consumer->_idResCheckNonce($this->response, $this->endpoint);
        $this->assertTrue(is_a($result, 'Auth_OpenID_FailureResponse'));
    }

    function test_badNonce()
    {
        // remove the nonce from the store
        $nonce = Auth_OpenID_mkNonce();
        list($timestamp, $salt) = Auth_OpenID_splitNonce($nonce);

        $this->store->useNonce($this->server_url, $timestamp, $salt);

        $response = Auth_OpenID_Message::fromOpenIDArgs(array(
                                                              'response_nonce' => $nonce,
                                                              'ns' => Auth_OpenID_OPENID2_NS
                                                              ));

        $result = $this->consumer->_idResCheckNonce($response,
                                                    $this->endpoint);

        $this->assertTrue(is_a($result, 'Auth_OpenID_FailureResponse'));
    }

    function test_tamperedNonce()
    {
        // Malformed nonce
        $query = array('response_nonce' => 'malformed',
                       'ns' => Auth_OpenID_OPENID2_NS);
        $message = Auth_OpenID_Message::fromPostArgs($query);

        $result = $this->consumer->_idResCheckNonce($message,
                                                    $this->endpoint);

        $this->assertTrue(is_a($result, 'Auth_OpenID_FailureResponse'));
    }

    function test_missingNonce()
    {
        // no nonce parameter on the return_to
        $query = array('openid.return_to' => $this->return_to);
        $message = Auth_OpenID_Message::fromPostArgs($query);

        $result = $this->consumer->_idResCheckNonce($message,
                                                    $this->endpoint);

        $this->assertTrue(is_a($result, 'Auth_OpenID_FailureResponse'));
    }
}

class Tests_Auth_OpenID_Consumer_TestCheckAuthTriggered extends _TestIdRes {
    var $consumer_class = '_CheckAuthDetectingConsumer';

    function _doIdRes($message, $endpoint)
    {
        return $this->consumer->_doIdRes($message, $endpoint);
    }

    function test_checkAuthTriggered()
    {
        $query = array('openid.return_to' => $this->return_to,
                       'openid.identity' => $this->server_id,
                       'openid.assoc_handle' =>'not_found',
                       'openid.sig' => 'bogus',
                       'openid.signed' => 'identity,return_to');

        $message = Auth_OpenID_Message::fromPostArgs($query);

        $result = $this->_doIdRes($message, $this->endpoint);

        $error = __getError();

        if ($error === null) {
            $this->fail('_checkAuth did not happen.');
        }
    }

    function test_checkAuthTriggeredWithAssoc()
    {
        // Store an association for this server that does not match
        // the handle that is in the query
        $issued = time();
        $lifetime = 1000;
        $assoc = new Auth_OpenID_Association(
                      'handle', 'secret', $issued, $lifetime, 'HMAC-SHA1');
        $this->store->storeAssociation($this->server_url, $assoc);

        $query = array(
            'openid.return_to' => $this->return_to,
            'openid.identity' => $this->server_id,
            'openid.assoc_handle' => 'not_found',
            'openid.sig' => 'bogus',
            'openid.signed' => 'return_to,identity');

        $message = Auth_OpenID_Message::fromPostArgs($query);

        $result = $this->_doIdRes($message, $this->endpoint);
        $error = __getError();

        if ($error === null) {
            $this->fail('_checkAuth did not happen.');
        }
    }

    function test_expiredAssoc()
    {
        // Store an expired association for the server with the handle
        // that is in the query
        $issued = time() - 10;
        $lifetime = 0;
        $handle = 'handle';
        $assoc = new Auth_OpenID_Association(
                        $handle, 'secret', $issued, $lifetime, 'HMAC-SHA1');
        $this->assertTrue($assoc->getExpiresIn() <= 0);
        $this->store->storeAssociation($this->server_url, $assoc);

        $query = array(
            'openid.return_to' => $this->return_to,
            'openid.identity' => $this->server_id,
            'openid.sig' => 'bogus',
            'openid.signed' => 'identity,return_to',
            'openid.assoc_handle' => $handle);

        $message = Auth_OpenID_Message::fromPostArgs($query);

        $info = $this->_doIdRes($message, $this->endpoint);

        $this->assertEquals('failure', $info->status);

        $this->assertTrue(strpos($info->message, 'expired') !== false);
    }

    function test_newerAssoc()
    {
        // Store an expired association for the server with the handle
        // that is in the query
        $lifetime = 1000;

        $good_issued = time() - 10;
        $good_handle = 'handle';
        $good_assoc = new Auth_OpenID_Association(
                $good_handle, 'secret', $good_issued, $lifetime, 'HMAC-SHA1');
        $this->store->storeAssociation($this->server_url, $good_assoc);

        $bad_issued = time() - 5;
        $bad_handle = 'handle2';
        $bad_assoc = new Auth_OpenID_Association(
                  $bad_handle, 'secret', $bad_issued, $lifetime, 'HMAC-SHA1');
        $this->store->storeAssociation($this->server_url, $bad_assoc);

        $query = array(
            'openid.return_to' => $this->return_to,
            'openid.identity' => $this->server_id,
            'openid.assoc_handle' => $good_handle);

        $message = Auth_OpenID_Message::fromPostArgs($query);
        $message = $good_assoc->signMessage($message);

        $info = $this->_doIdRes($message, $this->endpoint);

        $this->assertEquals($info->status, 'success');
        $this->assertEquals($this->consumer_id, $info->identity_url);
    }
}

class _MockFetcher {
    function _MockFetcher($response = null)
    {
        // response is (code, url, body)
        $this->response = $response;
        $this->fetches = array();
    }

    function post($url, $body)
    {
        $this->fetches[] = array($url, $body, array());
        return $this->response;
    }

    function get($url)
    {
        $this->fetches[] = array($url, null, array());
        return $this->response;
    }
}

class Tests_Auth_OpenID_Complete extends _TestIdRes {
    function test_cancel()
    {
        $query = array('openid.mode' => 'cancel');
        $message = Auth_OpenID_Message::fromPostArgs($query);

        $r = $this->consumer->complete($message, $this->endpoint);
        $this->assertEquals($r->status, Auth_OpenID_CANCEL);
        $this->assertTrue($r->identity_url == $this->endpoint->claimed_id);
    }

    function test_errorWithNoOptionalKeys()
    {
        $msg = 'an error message';
        $contact = 'some contact info here';
        $message = Auth_OpenID_Message::fromPostArgs(array('openid.mode'=> 'error',
                 'openid.error'=> $msg,
                                                           'openid.contact'=> $contact));

        $r = $this->consumer->complete($message, $this->endpoint);
        $this->assertEquals($r->status, Auth_OpenID_FAILURE);
        $this->assertTrue($r->identity_url == $this->endpoint->claimed_id);
        $this->assertTrue($r->contact == $contact);
        $this->assertTrue($r->reference === null);
        $this->assertEquals($r->message, $msg);
    }

    function test_errorWithOptionalKeys()
    {
        $msg = 'an error message';
        $contact = 'me';
        $reference = 'support ticket';
        $message = Auth_OpenID_Message::fromPostArgs(array('openid.mode'=> 'error',
                 'openid.error'=> $msg, 'openid.reference'=> $reference,
                 'openid.contact'=> $contact, 'openid.ns'=> Auth_OpenID_OPENID2_NS
                                                           ));
        $r = $this->consumer->complete($message, $this->endpoint);
        $this->assertEquals($r->status, Auth_OpenID_FAILURE);
        $this->assertTrue($r->identity_url == $this->endpoint->claimed_id);
        $this->assertTrue($r->contact == $contact);
        $this->assertTrue($r->reference == $reference);
        $this->assertEquals($r->message, $msg);
    }

    function test_error()
    {
        $msg = 'an error message';
        $query = array('openid.mode' =>'error',
                       'openid.error' => $msg);
        $message = Auth_OpenID_Message::fromPostArgs($query);
        $r = $this->consumer->complete($message, $this->endpoint);
        $this->assertEquals($r->status, Auth_OpenID_FAILURE);
        $this->assertTrue($r->identity_url == $this->endpoint->claimed_id);
        $this->assertEquals($r->message, $msg);
    }

    function test_noMode()
    {
        $query = array();
        $message = Auth_OpenID_Message::fromPostArgs($query);
        $r = $this->consumer->complete($message, $this->endpoint);
        $this->assertEquals($r->status, Auth_OpenID_FAILURE);
        $this->assertTrue($r->identity_url == $this->endpoint->claimed_id);
    }

    function test_idResMissingField()
    {
        $query = array('openid.mode' => 'id_res');
        $message = Auth_OpenID_Message::fromPostArgs($query);
        $r = $this->consumer->complete($message, $this->endpoint);
        $this->assertEquals($r->status, Auth_OpenID_FAILURE);
        $this->assertEquals($r->identity_url, $this->consumer_id);
    }

    function test_idResURLMismatch()
    {
        $query = array('openid.mode' => 'id_res',
                       'openid.return_to' => 'return_to (just anything)',
                       'openid.identity' => 'something wrong (not this->consumer_id)',
                       'openid.assoc_handle' => 'does not matter',
                       'openid.signed' => 'identity,return_to',
                       'openid.sig' => 'bogus');

        $message = Auth_OpenID_Message::fromPostArgs($query);
        $r = $this->consumer->complete($message, $this->endpoint);
        $this->assertEquals($r->status, Auth_OpenID_FAILURE);
        $this->assertEquals($r->identity_url, $this->consumer_id);
        $this->assertTrue(strpos($r->message, 'local_id') !== false);
    }
}

class SetupNeededConsumer extends Auth_OpenID_GenericConsumer {
    function _checkSetupNeeded($message)
    {
        return true;
    }
}

class Tests_Auth_OpenID_SetupNeeded extends _TestIdRes {
    function test_setupNeededIdRes()
    {
        $message = Auth_OpenID_Message::fromOpenIDArgs(array('mode'=> 'id_res'));
        $response = $this->consumer->complete($message, null);
        $this->assertEquals(Auth_OpenID_SETUP_NEEDED,
                            $response->status);
    }
}

class TempConsumer extends Auth_OpenID_GenericConsumer {
    function _verifyDiscoveryResults($message, $endpoint)
    {
        return $endpoint;
    }
}

class TestCompleteMissingSig extends PHPUnit_TestCase {

    function setUp()
    {
        global $GOODSIG;

        $this->store = new GoodAssocStore();
        $this->consumer = new Auth_OpenID_GenericConsumer($this->store);
        $this->server_url = "http://idp.unittest/";

        $claimed_id = 'bogus.claimed';

        $this->message = Auth_OpenID_Message::fromOpenIDArgs(
        array('mode'=> 'id_res',
              'return_to'=> 'return_to (just anything)',
              'identity'=> $claimed_id,
              'assoc_handle'=> 'does not matter',
              'sig'=> $GOODSIG,
              'response_nonce'=> Auth_OpenID_mkNonce(),
              'signed'=> 'identity,return_to,response_nonce,assoc_handle,claimed_id',
              'claimed_id'=> $claimed_id,
              'op_endpoint'=> $this->server_url,
              'ns' => Auth_OpenID_OPENID2_NS));

        $this->endpoint = new Auth_OpenID_ServiceEndpoint();
        $this->endpoint->server_url = $this->server_url;
        $this->endpoint->claimed_id = $claimed_id;
    }

    function test_idResMissingNoSigs()
    {
        $c = new TempConsumer($this->store);
        $r = $c->complete($this->message, $this->endpoint);
        $this->failUnlessSuccess($r);
    }

    function test_idResNoIdentity()
    {
        $this->message->delArg(Auth_OpenID_OPENID_NS, 'identity');
        $this->message->delArg(Auth_OpenID_OPENID_NS, 'claimed_id');
        $this->endpoint->claimed_id = null;
        $this->message->setArg(Auth_OpenID_OPENID_NS,
                               'signed', 'return_to,response_nonce,assoc_handle');
        $r = $this->consumer->complete($this->message, $this->endpoint);
        $this->failUnlessSuccess($r);
    }

    function test_idResMissingIdentitySig()
    {
        $this->message->setArg(Auth_OpenID_OPENID_NS,
                               'signed',
                               'return_to,response_nonce,assoc_handle,claimed_id');
        $r = $this->consumer->complete($this->message, $this->endpoint);
        $this->assertEquals($r->status, Auth_OpenID_FAILURE);
    }

    function test_idResMissingReturnToSig()
    {
        $this->message->setArg(Auth_OpenID_OPENID_NS,
                               'signed',
                               'identity,response_nonce,assoc_handle,claimed_id');
        $r = $this->consumer->complete($this->message, $this->endpoint);
        $this->assertEquals($r->status, Auth_OpenID_FAILURE);
    }

    function test_idResMissingAssocHandleSig()
    {
        $this->message->setArg(Auth_OpenID_OPENID_NS, 'signed',
                               'identity,response_nonce,return_to,claimed_id');
        $r = $this->consumer->complete($this->message, $this->endpoint);
        $this->assertEquals($r->status, Auth_OpenID_FAILURE);
    }

    function test_idResMissingClaimedIDSig()
    {
        $this->message->setArg(Auth_OpenID_OPENID_NS, 'signed',
                               'identity,response_nonce,return_to,assoc_handle');
        $r = $this->consumer->complete($this->message, $this->endpoint);
        $this->assertEquals($r->status, Auth_OpenID_FAILURE);
    }

    function failUnlessSuccess($response)
    {
        if ($response->status != Auth_OpenID_SUCCESS) {
            $this->fail(sprintf("Non-successful response: %s", $response));
        }
    }
}

class TestReturnToArgs extends PHPUnit_TestCase {
    function setUp()
    {
        $store = null;
        $this->consumer = new Auth_OpenID_GenericConsumer($store);
    }

    function test_returnToArgsOkay()
    {
        $query = array(
            'openid.mode'=> 'id_res',
            'openid.return_to'=> 'http://example.com/?foo=bar',
            'foo'=> 'bar'
            );
        // no return value, success is assumed if there are no exceptions.
        $result = $this->consumer->_verifyReturnToArgs($query);

        $this->assertFalse(is_a($result, 'Auth_OpenID_FailureResponse'));
        $this->assertTrue($result);
    }

    function test_returnToMismatch()
    {
        $query = array(
            'openid.mode' => 'id_res',
            'openid.return_to' => 'http://example.com/?foo=bar');

        // fail, query has no key 'foo'.
        $result = $this->consumer->_verifyReturnToArgs($query);
        $this->assertTrue(is_a($result, 'Auth_OpenID_FailureResponse'));

        $query['foo'] = 'baz';
        // fail, values for 'foo' do not match.
        $result = $this->consumer->_verifyReturnToArgs($query);
        $this->assertTrue(is_a($result, 'Auth_OpenID_FailureResponse'));
    }

    function test_noReturnTo()
    {
        $query = array('openid.mode'=> 'id_res');
        $result = $this->consumer->_verifyReturnToArgs($query);
        $this->assertTrue(is_a($result, 'Auth_OpenID_FailureResponse'));
    }

    function test_completeBadReturnTo()
    {
        // Test GenericConsumer.complete()'s handling of bad return_to
        // values.
        $return_to = "http://some.url/path?foo=bar";

        // Scheme, authority, and path differences are checked by
        // GenericConsumer._checkReturnTo.  Query args checked by
        // GenericConsumer._verifyReturnToArgs.
        $bad_return_tos = array(
                                // Scheme only
                                "https://some.url/path?foo=bar",
                                // Authority only
                                "http://some.url.invalid/path?foo=bar",
                                // Path only
                                "http://some.url/path_extra?foo=bar",
                                // Query args differ
                                "http://some.url/path?foo=bar2",
                                "http://some.url/path?foo2=bar"
                                );

        $m = new Auth_OpenID_Message(Auth_OpenID_OPENID1_NS);
        $m->setArg(Auth_OpenID_OPENID_NS, 'mode', 'cancel');
        $m->setArg(Auth_OpenID_BARE_NS, 'foo', 'bar');
        $endpoint = null;

        foreach ($bad_return_tos as $bad) {
            $m->setArg(Auth_OpenID_OPENID_NS, 'return_to', $bad);
            $result = $this->consumer->complete($m, $endpoint, $return_to);
            $this->assertTrue(is_a($result, 'Auth_OpenID_FailureResponse'));
            $this->assertTrue($result->message ==
                              "openid.return_to does not match return URL");
        }
    }

    function test_completeGoodReturnTo()
    {
        // Test GenericConsumer.complete()'s handling of good
        // return_to values.
        $return_to = "http://some.url/path";

        $good_return_tos = array(
                                 array($return_to,
                                       array()),
                                 array($return_to . "?another=arg",
                                       array(array(
                                                   array(Auth_OpenID_BARE_NS, 'another'),
                                                   'arg'))),
                                 array($return_to . "?another=arg#fragment",
                                       array(array(
                                                   array(Auth_OpenID_BARE_NS, 'another'),
                                                   'arg')))
                                 );

        $endpoint = null;

        foreach ($good_return_tos as $pair) {
            list($good_return_to, $extra) = $pair;
            $m = new Auth_OpenID_Message(Auth_OpenID_OPENID1_NS);
            $m->setArg(Auth_OpenID_OPENID_NS, 'mode', 'cancel');

            for ($i = 0; $i < count($extra); $i++) {
                list($ckey, $value) = $extra[$i];
                $ns = $ckey[0];
                $key = $ckey[1];
                $m->setArg($ns, $key, $value);
            }

            $m->setArg(Auth_OpenID_OPENID_NS, 'return_to', $good_return_to);
            $result = $this->consumer->complete($m, $endpoint, $return_to);
            $this->assertTrue(is_a($result, 'Auth_OpenID_CancelResponse'));
        }
    }
}

class Tests_Auth_OpenID_CheckAuthResponse extends _TestIdRes {
    function _createAssoc()
    {
        $issued = time();
        $lifetime = 1000;
        $assoc = new Auth_OpenID_Association(
                        'handle', 'secret', $issued, $lifetime, 'HMAC-SHA1');
        $store =& $this->consumer->store;
        $store->storeAssociation($this->server_url, $assoc);
        $assoc2 = $store->getAssociation($this->server_url);
        $this->assertEquals($assoc, $assoc2);
    }

    function test_goodResponse()
    {
        // successful response to check_authentication
        $response = array('is_valid' => 'true');
        $message = Auth_OpenID_Message::fromOpenIDArgs($response);
        $r = $this->consumer->_processCheckAuthResponse($message, $this->server_url);
        $this->assertTrue($r);
    }

    function test_missingAnswer()
    {
        // check_authentication returns false when the server sends no
        // answer
        $response = array();
        $message = Auth_OpenID_Message::fromPostArgs($response);
        $r = $this->consumer->_processCheckAuthResponse($message, $this->server_url);
        $this->assertFalse($r);
    }

    function test_badResponse()
    {
        // check_authentication returns false when is_valid is false
        $response = array('is_valid' => 'false');
        $message = Auth_OpenID_Message::fromOpenIDArgs($response);

        $r = $this->consumer->_processCheckAuthResponse($message, $this->server_url);
        $this->assertFalse($r);
    }

    function test_badResponseInvalidate()
    {
        // Make sure that the handle is invalidated when is_valid is
        // false
        $this->_createAssoc();
        $response = array('is_valid' => 'false',
                          'invalidate_handle' => 'handle');

        $message = Auth_OpenID_Message::fromOpenIDArgs($response);

        $r = $this->consumer->_processCheckAuthResponse($message,
                                                        $this->server_url);
        $this->assertFalse($r);
        $this->assertTrue(
                $this->consumer->store->getAssociation($this->server_url) === null);
    }

    function test_invalidateMissing()
    {
        // invalidate_handle with a handle that is not present
        $response = array('is_valid' => 'true',
                          'invalidate_handle' => 'missing');

        $message = Auth_OpenID_Message::fromOpenIDArgs($response);

        $r = $this->consumer->_processCheckAuthResponse($message, $this->server_url);
        $this->assertTrue($r);
    }

    function test_invalidatePresent()
    {
        // invalidate_handle with a handle that exists"""
        $this->_createAssoc();
        $response = array('is_valid' => 'true',
                          'invalidate_handle' => 'handle');

        $message = Auth_OpenID_Message::fromOpenIDArgs($response);

        $r = $this->consumer->_processCheckAuthResponse($message, $this->server_url);
        $this->assertTrue($r);
        $this->assertTrue(
                  $this->consumer->store->getAssociation($this->server_url) === null);
    }
}

class _IdResFetchFailingConsumer extends Auth_OpenID_GenericConsumer {
    var $message = 'fetch failed';

    function _doIdRes($message, $endpoint)
    {
        return new Auth_OpenID_FailureResponse($endpoint,
                                               $this->message);
    }
}

class Tests_Auth_OpenID_FetchErrorInIdRes extends _TestIdRes {
    var $consumer_class = '_IdResFetchFailingConsumer';

    function test_idResFailure()
    {
        $query = array('openid.mode' => 'id_res');
        $message = Auth_OpenID_Message::fromPostArgs($query);
        $r = $this->consumer->complete($message, $this->endpoint);
        $this->assertEquals($r->status, Auth_OpenID_FAILURE);
        $this->assertEquals($r->identity_url, $this->consumer_id);
        $this->assertEquals($this->consumer->message, $r->message);
    }
}

class _ExceptionRaisingMockFetcher {
    function get($url)
    {
        __raiseError(E_MOCK_FETCHER_EXCEPTION);
    }

    function post($url, $body)
    {
        __raiseError(E_MOCK_FETCHER_EXCEPTION);
    }
}

class _BadArgCheckingConsumer extends Auth_OpenID_GenericConsumer {
    function _makeKVPost($message, $tmp)
    {
        $args = $message->toPostArgs();

        if ($args != array(
            'openid.mode' => 'check_authentication',
            'openid.signed' => 'foo')) {
            __raiseError(E_ASSERTION_ERROR);
        }
        return null;
    }
}

class Tests_Auth_OpenID_Consumer_TestCheckAuth extends _TestIdRes {
    function setUp()
    {
        $this->store = new Tests_Auth_OpenID_MemStore();
        $this->consumer = new Auth_OpenID_GenericConsumer($this->store);
        $this->fetcher = new _MockFetcher();
        $this->consumer->fetcher =& $this->fetcher;
    }

    function test_checkauth_error()
    {
        global $_Auth_OpenID_server_url;
        $this->fetcher->response = new Services_Yadis_HTTPResponse("http://some_url",
                                                                404,
                                                                array(),
                                                                "blah:blah\n");
        $query = array('openid.signed' => 'stuff, things');
        $message = Auth_OpenID_Message::fromPostArgs($query);

        $r = $this->consumer->_checkAuth($message, $_Auth_OpenID_server_url);
        if ($r !== false) {
            $this->fail("Expected _checkAuth result to be false");
        }
    }

    function test_bad_args()
    {
        $query = array('openid.signed' => 'foo',
                       'closid.foo' => 'something');

        $consumer = new _BadArgCheckingConsumer($this->store);

        $message = Auth_OpenID_Message::fromPostArgs($query);

        $this->assertFalse($consumer->_checkAuth($message, 'does://not.matter'));
    }

    function test_signedList()
    {
        $query = Auth_OpenID_Message::fromOpenIDArgs(array(
            'mode'=> 'id_res',
            'sig'=> 'rabbits',
            'identity'=> '=example',
            'assoc_handle'=> 'munchkins',
            'signed'=> 'identity,mode',
            'foo'=> 'bar'));

        $expected = Auth_OpenID_Message::fromOpenIDArgs(array(
            'mode'=> 'check_authentication',
            'sig'=> 'rabbits',
            'assoc_handle'=> 'munchkins',
            'identity'=> '=example',
            'signed'=> 'identity,mode'
            ));

        $args = $this->consumer->_createCheckAuthRequest($query);
        $this->assertEquals($args->toPostArgs(), $expected->toPostArgs());
    }
}

class Tests_Auth_OpenID_Consumer_TestFetchAssoc extends PHPUnit_TestCase {
    function setUp()
    {
        $this->store = new Tests_Auth_OpenID_MemStore();
        $this->fetcher = new _MockFetcher();
        $this->consumer = new Auth_OpenID_GenericConsumer($this->store);
        $this->consumer->fetcher =& $this->fetcher;
    }

    function test_kvpost_error()
    {
        $this->fetcher->response = new Services_Yadis_HTTPResponse("http://some_url",
                                                                   404,
                                                                   array(),
                                                                   "blah:blah\n");
        $query = array('openid.mode' => 'associate');
        $message = Auth_OpenID_Message::fromPostArgs($query);

        $r = $this->consumer->_makeKVPost($message,
                                          "http://server_url");
        if ($r !== null) {
            $this->fail("Expected _makeKVPost result to be null");
        }
    }

    function test_error_404()
    {
        // 404 from a kv post raises HTTPFetchingError
        $this->fetcher->response = new Services_Yadis_HTTPResponse(
           "http://some_url", 404, array('Hea'=> 'der'), 'blah:blah\n');

        $result = $this->consumer->_makeKVPost(
               Auth_OpenID_Message::fromPostArgs(array('mode'=>'associate')),
               "http://server_url");

        $this->assertTrue($result === null);
    }

    function test_error_exception()
    {
        $this->consumer->fetcher = new _ExceptionRaisingMockFetcher();

        $query = array('openid.mode' => 'associate');
        $message = Auth_OpenID_Message::fromPostArgs($query);

        $this->consumer->_makeKVPost($message,
                                     "http://server_url");

        if (__getError() !== E_MOCK_FETCHER_EXCEPTION) {
            $this->fail("Expected ExceptionRaisingMockFetcher to " .
                        "raise E_MOCK_FETCHER_EXCEPTION");
        }

        $endpoint = new Auth_OpenID_ServiceEndpoint();
        $endpoint->server_url = 'some://url';

        // exception fetching returns no association
        $this->assertEquals($this->consumer->_getAssociation($endpoint),
                            null);

        $query = array('openid.signed' => '');
        $message = Auth_OpenID_Message::fromPostArgs($query);

        $this->consumer->_checkAuth($message,
                                    'some://url');

        if (__getError() !== E_MOCK_FETCHER_EXCEPTION) {
            $this->fail("Expected ExceptionRaisingMockFetcher to " .
                        "raise E_MOCK_FETCHER_EXCEPTION (_checkAuth)");
        }
    }
}

class Tests_Auth_OpenID_AuthRequest extends PHPUnit_TestCase {
    function setUp()
    {
        $this->endpoint = new Auth_OpenID_ServiceEndpoint();
        $this->endpoint->local_id = 'http://server.unittest/joe';
        $this->endpoint->server_url = 'http://server.unittest/';
        $this->assoc =& $this;
        $this->assoc->handle = 'assoc@handle';
        $this->authreq = new Auth_OpenID_AuthRequest($this->endpoint, $this->assoc);
    }

    function test_addExtensionArg()
    {
        $this->authreq->addExtensionArg('bag', 'color', 'brown');
        $this->authreq->addExtensionArg('bag', 'material', 'paper');

        $m = $this->authreq->getMessage('realm', 'return_to');

        $this->assertTrue($m->hasKey('bag', 'color'));
        $this->assertTrue($m->hasKey('bag', 'material'));
    }
}

class Tests_Auth_OpenID_SuccessResponse extends PHPUnit_TestCase {
    function setUp()
    {
        $this->endpoint = new Auth_OpenID_ServiceEndpoint();
        $this->endpoint->claimed_id = 'identity_url';
    }

    function test_extensionResponse()
    {
        $uri = "http://bogus.unittest/1.0";

        $query = array(
            'openid.ns.unittest' => $uri,
            'openid.unittest.one' => '1',
            'openid.unittest.two' =>'2',
            'openid.sreg.nickname' => 'j3h',
            'openid.return_to' => 'return_to');

        $message = Auth_OpenID_Message::fromPostArgs($query);
        $resp = new Auth_OpenID_SuccessResponse($this->endpoint, $message);

        $utargs = $resp->extensionResponse($uri, false);
        $this->assertEquals($utargs, array('one' => '1', 'two' => '2'));
        $sregargs = $resp->extensionResponse(Auth_OpenID_SREG_URI, false);
        $this->assertEquals($sregargs, array('nickname' => 'j3h'));
    }

    function test_extensionResponseSigned()
    {
        $args = array(
            'ns.sreg' => 'urn:sreg',
            'ns.unittest' => 'urn:unittest',
            'unittest.one' => '1',
            'unittest.two' => '2',
            'sreg.nickname' => 'j3h',
            'sreg.dob' => 'yesterday',
            'return_to' => 'return_to',
            'signed' => 'sreg.nickname,unittest.one,sreg.dob');

        $signed_list = array('openid.sreg.nickname',
                             'openid.unittest.one',
                             'openid.sreg.dob');

        $msg = Auth_OpenID_Message::fromOpenIDArgs($args);
        $resp = new Auth_OpenID_SuccessResponse($this->endpoint, $msg, $signed_list);

        // All args in this NS are signed, so expect all.
        $sregargs = $resp->extensionResponse('urn:sreg', true);
        $this->assertEquals($sregargs,
                            array('nickname' => 'j3h',
                                  'dob' => 'yesterday'));

        // Not all args in this NS are signed, so expect null when
        // asking for them.
        $utargs = $resp->extensionResponse('urn:unittest', true);
        $this->assertEquals($utargs, null);
    }

    function test_noReturnTo()
    {
        $message = Auth_OpenID_Message::fromPostArgs(array());
        $resp = new Auth_OpenID_SuccessResponse($this->endpoint, $message);
        $this->assertTrue($resp->getReturnTo() === null);
    }

    function test_returnTo()
    {
        $query = array('openid.return_to' => 'return_to');
        $message = Auth_OpenID_Message::fromPostArgs($query);

        $resp = new Auth_OpenID_SuccessResponse($this->endpoint,
                                                $message, array('openid.return_to'));

        $this->assertEquals($resp->getReturnTo(), 'return_to');
    }
}

class _StubConsumer {
    function _StubConsumer()
    {
        $this->assoc = null;
        $this->response = null;
        $this->endpoint = null;
        $this->fetcher = new _MockFetcher();
    }

    function begin($service)
    {
        $auth_req = new Auth_OpenID_AuthRequest($service, $this->assoc);
        $this->endpoint = $service;
        return $auth_req;
    }

    function complete($message, $endpoint)
    {
        return $this->response;
    }
}

class Tests_Auth_OpenID_DiscoFailure extends PHPUnit_TestCase {
    var $consumerClass = null;

    function setUp()
    {
        foreach ($_SESSION as $k => $v) {
          unset($_SESSION[$k]);
        }

        $this->endpoint = new Auth_OpenID_ServiceEndpoint();
        $this->claimed_id = 'http://identity.url/';
        $this->endpoint->claimed_id = $this->claimed_id;
        $this->store = null;
        $this->session = new Services_Yadis_PHPSession();
        $cls = $this->consumerClass;
        $this->consumer =& new $cls($this->store, &$this->session);
        $this->consumer->consumer =& new _StubConsumer();
        $this->discovery =& new Services_Yadis_Discovery(&$this->session,
                                         $this->claimed_id,
                                         $this->consumer->session_key_prefix);
    }
}

class Tests_Auth_OpenID_ConsumerTest2 extends PHPUnit_TestCase {
    function setUp()
    {
        foreach ($_SESSION as $k => $v) {
          unset($_SESSION[$k]);
        }

        $this->endpoint = new Auth_OpenID_ServiceEndpoint();
        $this->claimed_id = 'http://identity.url/';
        $this->endpoint->claimed_id = $this->claimed_id;
        $this->store = null;
        $this->session = new Services_Yadis_PHPSession();
        $this->consumer =& new Auth_OpenID_Consumer($this->store, $this->session);
        $this->consumer->consumer =& new _StubConsumer();
        $this->discovery =& new Services_Yadis_Discovery(&$this->session,
                                         $this->claimed_id,
                                         $this->consumer->session_key_prefix);
    }

    function test_beginWithoutDiscovery()
    {
        // Does this really test anything non-trivial?
        $result = $this->consumer->beginWithoutDiscovery($this->endpoint);

        // The result is an auth request
        $this->assertTrue(strtolower(get_class($result)) ==
                          'auth_openid_authrequest');

        $loader = new Auth_OpenID_ServiceEndpointLoader();

        // Side-effect of calling beginWithoutDiscovery is setting the
        // session value to the endpoint attribute of the result
        $this->assertTrue(
                $loader->fromSession(
                       $this->session->get($this->consumer->_token_key)) ==
                $result->endpoint);

        // The endpoint that we passed in is the endpoint on the
        // auth_request
        $this->assertTrue($result->endpoint == $this->endpoint);
    }

    function test_completeEmptySession()
    {
        $response = $this->consumer->complete(array());
        $this->assertEquals($response->status, Auth_OpenID_FAILURE);
        $this->assertTrue($response->identity_url === null);
    }

    function _doResp($auth_req, $exp_resp)
    {
        // complete a transaction, using the expected response from
        // the generic consumer.
        $this->consumer->consumer->response = $exp_resp;

        // endpoint is stored in the session
        // $this->assertTrue($this->session->data);
        $this->assertTrue($_SESSION);
        $resp = $this->consumer->complete(array());

        // All responses should have the same identity URL, and the
        // session should be cleaned out
        $this->assertTrue($resp->identity_url == $this->claimed_id);
        $this->assertFalse(in_array($this->consumer->_token_key,
                                    $_SESSION)); // this->session->data));

        // Expected status response
        $this->assertEquals($resp->status, $exp_resp->status);

        return $resp;
    }

    function _doRespNoDisco($exp_resp)
    {
        // Set up a transaction without discovery
        $auth_req = $this->consumer->beginWithoutDiscovery($this->endpoint);
        $resp = $this->_doResp($auth_req, $exp_resp);
        // There should be nothing left in the session once we have
        // completed.
        $this->assertFalse($this->session->contents());
        return $resp;
    }

    function test_noDiscoCompleteSuccessWithToken()
    {
        $message = Auth_OpenID_Message::fromPostArgs(array());
        $this->_doRespNoDisco(new Auth_OpenID_SuccessResponse($this->endpoint,
                                                              $message));
    }

    function test_noDiscoCompleteCancelWithToken()
    {
        $this->_doRespNoDisco(new Auth_OpenID_CancelResponse($this->endpoint));
    }

    function test_noDiscoCompleteFailure()
    {
        $msg = 'failed!';
        $resp = $this->_doRespNoDisco(new Auth_OpenID_FailureResponse($this->endpoint, $msg));
        $this->assertTrue($resp->message == $msg);
    }

    function test_noDiscoCompleteSetupNeeded()
    {
        $setup_url = 'http://setup.url/';
        $resp = $this->_doRespNoDisco(
              new Auth_OpenID_SetupNeededResponse($this->endpoint, $setup_url));
        $this->assertTrue($resp->setup_url == $setup_url);
    }

    // To test that discovery is cleaned up, we need to initialize a
    // Yadis manager, and have it put its values in the session.
    function _doRespDisco($is_clean, $exp_resp)
    {
        // Set up and execute a transaction, with discovery
        $this->discovery->createManager(array($this->endpoint),
                                        $this->claimed_id);
        $auth_req = $this->consumer->begin($this->claimed_id);
        $resp = $this->_doResp($auth_req, $exp_resp);

        $manager = $this->discovery->getManager();
        if ($is_clean) {
            $this->assertTrue($this->discovery->getManager() === null);
        } else {
            $this->assertFalse($this->discovery->getManager() === null);
        }

        return $resp;
    }

    // Cancel and success DO clean up the discovery process
    function test_completeSuccess()
    {
        $message = Auth_OpenID_Message::fromPostArgs(array());
        $this->_doRespDisco(true,
                            new Auth_OpenID_SuccessResponse($this->endpoint,
                                                            $message));
    }

    function test_completeCancel()
    {
        $this->_doRespDisco(true,
                            new Auth_OpenID_CancelResponse($this->endpoint));
    }

    // Failure and setup_needed don't clean up the discovery process
    function test_completeFailure()
    {
        $msg = 'failed!';
        $resp = $this->_doRespDisco(false,
                    new Auth_OpenID_FailureResponse($this->endpoint, $msg));
        $this->assertTrue($resp->message == $msg);
    }

    function test_completeSetupNeeded()
    {
        $setup_url = 'http://setup.url/';
        $resp = $this->_doRespDisco(false,
            new Auth_OpenID_SetupNeededResponse($this->endpoint, $setup_url));
        $this->assertTrue($resp->status == Auth_OpenID_SETUP_NEEDED);
        $this->assertTrue($resp->setup_url == $setup_url);
    }

    function test_begin()
    {
        $this->discovery->createManager(array($this->endpoint),
                                        $this->claimed_id);
        // Should not raise an exception
        $auth_req = $this->consumer->begin($this->claimed_id);
        $this->assertTrue(strtolower(get_class($auth_req)) === 'auth_openid_authrequest');
        $this->assertTrue($auth_req->endpoint == $this->endpoint);
        $this->assertTrue($auth_req->endpoint == $this->consumer->consumer->endpoint);
        $this->assertTrue($auth_req->assoc == $this->consumer->consumer->assoc);
    }
}

class IDPDrivenTest_Consumer1 extends Auth_OpenID_GenericConsumer {
    var $iverified = array();
    var $endpoint = null;

    function _idResCheckNonce($message, $endpoint)
    {
        return true;
    }

    function _verifyDiscoveryResults($identifier, $server_url)
    {
        $this->iverified[] = $this->endpoint;
        return $this->endpoint;
    }
}

class IDPDrivenTest_Consumer2 extends Auth_OpenID_GenericConsumer {
    function verifyDiscoveryResults($identifier, $server_url)
    {
        return new Auth_OpenID_FailureResponse(null,
                                               "Bogus");
    }
}

class IDPDrivenTest extends PHPUnit_TestCase {
    function setUp()
    {
        $this->store = new GoodAssocStore();
        $this->consumer = new Auth_OpenID_GenericConsumer($this->store);
        $this->endpoint = new Auth_OpenID_ServiceEndpoint();
        $this->endpoint->server_url = "http://idp.unittest/";
        $this->endpoint->type_uris = array('http://openid.net/server/2.0');
    }

    function test_idpDrivenBegin()
    {
        // Testing here that the token-handling doesn't explode...
        $this->assertTrue($this->consumer->begin($this->endpoint) !== null);
    }

    function test_idpDrivenComplete()
    {
        global $GOODSIG;

        $this->consumer = new IDPDrivenTest_Consumer1($this->store);

        $identifier = '=directed_identifier';
        $message = Auth_OpenID_Message::fromPostArgs(array(
            'openid.identity'=> '=directed_identifier',
            'openid.return_to'=> 'x',
            'openid.assoc_handle'=> 'z',
            'openid.signed'=> 'identity,return_to',
            'openid.sig'=> $GOODSIG));

        $endpoint = new Auth_OpenID_ServiceEndpoint();
        $endpoint->claimed_id = $identifier;
        $endpoint->server_url = $this->endpoint->server_url;
        $endpoint->local_id = $identifier;

        $this->consumer->endpoint =& $endpoint;
        $response = $this->consumer->_doIdRes($message, $this->endpoint);

        $this->failUnlessSuccess($response);

        $this->assertEquals($response->identity_url, "=directed_identifier");

        // assert that discovery attempt happens and returns good
        $this->assertEquals($this->consumer->iverified, array($endpoint));
    }

    function test_idpDrivenCompleteFraud()
    {
        global $GOODSIG;

        $this->consumer = new IDPDrivenTest_Consumer2($this->store);

        // crap with an identifier that doesn't match discovery info
        $message = Auth_OpenID_Message::fromPostArgs(array(
            'openid.identity'=> '=directed_identifier',
            'openid.return_to'=> 'x',
            'openid.assoc_handle'=> 'z',
            'openid.signed'=> 'identity,return_to',
            'openid.sig'=> $GOODSIG));

        $result = $this->consumer->_doIdRes($message, $this->endpoint);
        $this->assertTrue(is_a($result, 'Auth_OpenID_FailureResponse'));
    }

    function failUnlessSuccess($response)
    {
        if ($response->status != Auth_OpenID_SUCCESS) {
            $this->fail("Non-successful response (status is ".$response->status.")");
        }
    }
}

class TestDiscoveryVerification extends PHPUnit_TestCase {
    var $services = array();

    function discoveryFunc($identifier)
    {
        return array($identifier, $this->services);
    }

    function setUp()
    {
        $this->store = new GoodAssocStore();
        $this->consumer = new Auth_OpenID_GenericConsumer($this->store);
        $this->consumer->discoverMethod = array($this,
                                                'discoveryFunc');

        $this->identifier = "http://idp.unittest/1337";
        $this->server_url = "http://endpoint.unittest/";

        $this->message = Auth_OpenID_Message::fromPostArgs(array(
            'openid.ns'=> Auth_OpenID_OPENID2_NS,
            'openid.identity'=> $this->identifier,
            'openid.claimed_id'=> $this->identifier,
            'openid.op_endpoint'=> $this->server_url));

        $this->endpoint = new Auth_OpenID_ServiceEndpoint();
        $this->endpoint->server_url = $this->server_url;
    }

    function test_theGoodStuff()
    {
        $endpoint = new Auth_OpenID_ServiceEndpoint();
        $endpoint->type_uris = array(Auth_OpenID_OPENID2_NS);
        $endpoint->claimed_id = $this->identifier;
        $endpoint->server_url = $this->server_url;
        $endpoint->local_id = $this->identifier;
        $this->services = array($endpoint);
        $r = $this->consumer->_verifyDiscoveryResults($this->message, $endpoint);

        $this->assertEquals($r, $endpoint);
    }

    function test_otherServer()
    {
        // a set of things without the stuff
        $endpoint = new Auth_OpenID_ServiceEndpoint();
        $endpoint->type_uris = array(Auth_OpenID_OPENID2_NS);
        $endpoint->claimed_id = $this->identifier;
        $endpoint->server_url = "http://the-MOON.unittest/";
        $endpoint->local_id = $this->identifier;
        $this->services = array($endpoint);

        $result = $this->consumer->_verifyDiscoveryResults(
                                 $this->message, $endpoint);

        $this->assertTrue(is_a($result, 'Auth_OpenID_FailureResponse'));
    }

    function test_foreignDelegate()
    {
        // a set of things with the server stuff but other delegate
        $endpoint = new Auth_OpenID_ServiceEndpoint();
        $endpoint->type_uris = array(Auth_OpenID_OPENID2_NS);
        $endpoint->claimed_id = $this->identifier;
        $endpoint->server_url = $this->server_url;
        $endpoint->local_id = "http://unittest/juan-carlos";

        $result = $this->consumer->_verifyDiscoveryResults(
                                 $this->message, $endpoint);
        $this->assertTrue(is_a($result, 'Auth_OpenID_FailureResponse'));
    }

    function test_nothingDiscovered()
    {
        // a set of no things.
        $this->services = array();
        $result = $this->consumer->_verifyDiscoveryResults(
                                 $this->message, $this->endpoint);
        $this->assertTrue(is_a($result, 'Auth_OpenID_FailureResponse'));
    }
}

class DummyEndpoint {
    var $use_compatibility = false;

    function compatibilityMode()
    {
        return $this->use_compatibility;
    }
}

class FastConsumerSession extends Auth_OpenID_DiffieHellmanSHA1ConsumerSession {
    function FastConsumerSession($dh = null)
    {
        if ($dh === null) {
            $dh = new Auth_OpenID_DiffieHellman(100389557, 2);
        }

        $this->dh = $dh;
    }
}

function setConsumerSession(&$con)
{
    $con->session_types = array('DH-SHA1' => 'FastConsumerSession');
}

class TestCreateAssociationRequest extends PHPUnit_TestCase {
    function setUp()
    {
        $this->endpoint = new DummyEndpoint();
        $s = null;
        $this->consumer = new Auth_OpenID_GenericConsumer($s);
        $this->assoc_type = 'HMAC-SHA1';
    }

    function test_noEncryptionSendsType()
    {
        $session_type = 'no-encryption';
        list($session, $args) = $this->consumer->_createAssociateRequest(
                       $this->endpoint, $this->assoc_type, $session_type);

        $this->assertTrue(is_a($session, 'Auth_OpenID_PlainTextConsumerSession'));

        $expected = Auth_OpenID_Message::fromOpenIDArgs(
                                                        array('ns' => Auth_OpenID_OPENID2_NS,
                                                              'session_type'=>$session_type,
                                                              'mode'=>'associate',
                                                              'assoc_type'=>$this->assoc_type));

        $this->assertEquals($expected->toPostArgs(),
                            $args->toPostArgs());
    }

    function test_noEncryptionCompatibility()
    {
        $this->endpoint->use_compatibility = true;
        $session_type = 'no-encryption';
        list($session, $args) = $this->consumer->_createAssociateRequest(
                       $this->endpoint, $this->assoc_type, $session_type);

        $this->assertTrue(is_a($session, 'Auth_OpenID_PlainTextConsumerSession'));
        $this->assertEquals(Auth_OpenID_Message::fromOpenIDArgs(array('mode'=>'associate',
                                                                      'assoc_type'=>$this->assoc_type)),
                            $args);
    }

    function test_dhSHA1Compatibility()
    {
        // Set the consumer's session type to a fast session since we
        // need it here.
        setConsumerSession($this->consumer);

        $this->endpoint->use_compatibility = true;
        $session_type = 'DH-SHA1';
        list($session, $args) = $this->consumer->_createAssociateRequest(
                       $this->endpoint, $this->assoc_type, $session_type);

        $this->assertTrue(is_a($session,
                               'Auth_OpenID_DiffieHellmanSHA1ConsumerSession'));

        // This is a random base-64 value, so just check that it's
        // present.
        $this->assertTrue($args->hasKey(Auth_OpenID_OPENID1_NS, 'dh_consumer_public'));
        $args->delArg(Auth_OpenID_OPENID1_NS, 'dh_consumer_public');

        // OK, session_type is set here and not for no-encryption
        // compatibility
        $expected = Auth_OpenID_Message::fromOpenIDArgs(array('mode'=>'associate',
                                                              'session_type'=>'DH-SHA1',
                                                              'assoc_type'=>$this->assoc_type,
                                                              'dh_modulus'=> 'BfvStQ==',
                                                              'dh_gen'=> 'Ag=='));

        $this->assertEquals($expected->toPostArgs(),
                            $args->toPostArgs());
    }
}

class TestDiffieHellmanResponseParameters extends PHPUnit_TestCase {
    var $session_cls = null;
    var $message_namespace = null;

    function setUp()
    {
        // Pre-compute DH with small prime so tests run quickly.
        $this->server_dh = new Auth_OpenID_DiffieHellman(100389557, 2);
        $this->consumer_dh = new Auth_OpenID_DiffieHellman(100389557, 2);

        $lib =& Auth_OpenID_getMathLib();

        $cls = $this->session_cls;
        $this->consumer_session = new $cls($this->consumer_dh);

        // base64(btwoc(g ^ xb mod p))
        $this->dh_server_public = $lib->longToBase64($this->server_dh->public);

        $this->secret = Auth_OpenID_CryptUtil::randomString(
                            $this->consumer_session->secret_size);

        $this->enc_mac_key = base64_encode(
            $this->server_dh->xorSecret($this->consumer_dh->public,
                                        $this->secret,
                                        $this->consumer_session->hash_func));

        $this->msg = new Auth_OpenID_Message($this->message_namespace);
    }

    function testExtractSecret()
    {
        $this->msg->setArg(Auth_OpenID_OPENID_NS, 'dh_server_public',
                           $this->dh_server_public);

        $this->msg->setArg(Auth_OpenID_OPENID_NS, 'enc_mac_key',
                           $this->enc_mac_key);

        $extracted = $this->consumer_session->extractSecret($this->msg);
        $this->assertEquals($extracted, $this->secret);
    }

    function testAbsentServerPublic()
    {
        $this->msg->setArg(Auth_OpenID_OPENID_NS, 'enc_mac_key',
                           $this->enc_mac_key);

        $this->assertTrue($this->consumer_session->extractSecret($this->msg) === null);
    }

    function testAbsentMacKey()
    {
        $this->msg->setArg(Auth_OpenID_OPENID_NS, 'dh_server_public',
                           $this->dh_server_public);

        $this->assertTrue($this->consumer_session->extractSecret($this->msg) === null);
    }

    /*
    function testInvalidBase64Public()
    {
        $this->msg->setArg(Auth_OpenID_OPENID_NS, 'dh_server_public',
                           'n o t b a s e 6 4.');

        $this->msg->setArg(Auth_OpenID_OPENID_NS, 'enc_mac_key',
                           $this->enc_mac_key);

        $this->assertTrue($this->consumer_session->extractSecret($this->msg) === null);
    }

    function testInvalidBase64MacKey()
    {
        $this->msg->setArg(Auth_OpenID_OPENID_NS, 'dh_server_public',
                           $this->dh_server_public);

        $this->msg->setArg(Auth_OpenID_OPENID_NS, 'enc_mac_key',
                           'n o t base 64');

        $this->assertTrue($this->consumer_session->extractSecret($this->msg) === null);
    }
    */
}

class TestOpenID1SHA1 extends TestDiffieHellmanResponseParameters {
    var $session_cls = 'Auth_OpenID_DiffieHellmanSHA1ConsumerSession';
    var $message_namespace = Auth_OpenID_OPENID1_NS;
}

class TestOpenID2SHA1 extends TestDiffieHellmanResponseParameters {
    var $session_cls = 'Auth_OpenID_DiffieHellmanSHA1ConsumerSession';
    var $message_namespace = Auth_OpenID_OPENID2_NS;
}

if (!defined('Auth_OpenID_NO_MATH_SUPPORT') &&
    Auth_OpenID_SHA256_SUPPORTED) {
    class TestOpenID2SHA256 extends TestDiffieHellmanResponseParameters {
        var $session_cls = 'Auth_OpenID_DiffieHellmanSHA256ConsumerSession';
        var $message_namespace = Auth_OpenID_OPENID2_NS;
    }
}

// Add other test cases to be run.
global $Tests_Auth_OpenID_Consumer_other;
$Tests_Auth_OpenID_Consumer_other = array(
                                          // new Tests_Auth_OpenID_Consumer_TestSetupNeeded(),
                                          new Tests_Auth_OpenID_Consumer_TestCheckAuth(),
                                          new Tests_Auth_OpenID_Consumer_TestCheckAuthTriggered(),
                                          new Tests_Auth_OpenID_Consumer_TestFetchAssoc(),
                                          new Tests_Auth_OpenID_Consumer_CheckNonceTest(),
                                          new Tests_Auth_OpenID_Complete(),
                                          new Tests_Auth_OpenID_SuccessResponse(),
                                          new Tests_Auth_OpenID_CheckAuthResponse(),
                                          new Tests_Auth_OpenID_FetchErrorInIdRes(),
                                          new Tests_Auth_OpenID_ConsumerTest2(),
                                          new Tests_Auth_OpenID_AuthRequest(),
                                          new Tests_Auth_OpenID_Stateless1(),
                                          new Tests_Auth_OpenID_Stateless2(),
                                          new TestCompleteMissingSig(),
                                          new TestReturnToArgs(),
                                          new IDPDrivenTest(),
                                          new TestDiscoveryVerification(),
                                          );

if (!defined('Auth_OpenID_NO_MATH_SUPPORT')) {
    $Tests_Auth_OpenID_Consumer_other[] = new TestCreateAssociationRequest();
    $Tests_Auth_OpenID_Consumer_other[] = new TestOpenID1SHA1();
    $Tests_Auth_OpenID_Consumer_other[] = new TestOpenID2SHA1();
}

if (!defined('Auth_OpenID_NO_MATH_SUPPORT') &&
    Auth_OpenID_SHA256_SUPPORTED) {
    $Tests_Auth_OpenID_Consumer_other[] = new TestOpenID2SHA256();
}

?>