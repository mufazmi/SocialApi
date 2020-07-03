<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

//use Slim\Factory\AppFactory;

require '../vendor/autoload.php';
require_once '../include/DbHandler.php';
require_once '../vendor/autoload.php';
require_once '../include/JWT.php';

$JWT = new JWT;

$app = new \Slim\App;
$app = new Slim\App([

    'settings' => [
        'displayErrorDetails' => true,
        'debug'               => true,
    ]
]);


$app->post('/createUser', function(Request $request, Response $response)
{
    if(!checkEmptyParameter(array('name','username','email','password'),$request,$response))
    {
        $db = new DbHandler();
        $requestParameter = $request->getParsedBody();
        $email = $requestParameter['email'];
        $password = $requestParameter['password'];
        $name = $requestParameter['name'];
        $username = $requestParameter['username'];
        $result = array();
        $result = $db->createUser($name,$username,$email,$password);

        if($result == USER_CREATION_FAILED)
        {
            returnException(true,"Failed to create an account",$response);
        }
        else if($result == EMAIL_EXIST)
        {
            returnException(true,"Email already registered",$response);
        }
        else if($result == USERNAME_EXIST)
        {
            returnException(true,"Username not available",$response);
        }
        else if($result == USER_CREATED)
        {
            $code = $db->getCodeByEmail($email);
            if(prepareVerificationMail($name,$email,$code))
            {
               returnException(false,"An Email Verification Link Has Been Sent To Your Email Address: ".$email,$response);
            }
            else
            {
               returnException(true,"Failed To Send Verification Email",$response);
            }
        }
        else if($result == VERIFICATION_EMAIL_SENT_FAILED)
        {
            returnException(true,"Failed To Send Verification Email",$response);
        }
        else if($result == EMAIL_NOT_VALID)
        {
            returnException(true,"Enter Valid Email",$response);
        }
    }
});

$app->post('/follow', function(Request $request, Response $response)
{
    $db = new DbHandler;
    if (validateToken($db,$request,$response)) 
    {
        $requestParameter = $request->getParsedBody();
        if (!checkEmptyParameter(array('id'),$request,$response)) 
        {
            $userId = $db->getUserId();
            $toUserId = $requestParameter['id'];
            if ($db->checkUserById($toUserId)) 
            {  
                if (!$db->checkFollowing($userId,$toUserId)) 
                {
                    $result = $db->doFollow($userId,$toUserId);
                    if ($result==FOLLOWED) 
                    {
                        $user = array();
                        $user['followersCount'] = $db->getFollowersCountById($toUserId);
                        returnUserResponse(false,"Followed",$user,$response);
                    }
                    else if ($result==FOLLOW_FAILED) 
                    {
                        returnException(true,"Failed To Follow",$response);
                    }
                }
                else
                {
                    returnException(true,"Already Following",$response);
                }
            }
            else
            {
                returnException(true,"User Not Found",$response);
            }
        }
    }
});

$app->post('/unFollow', function(Request $request, Response $response)
{
    $db = new DbHandler;
    if (validateToken($db,$request,$response)) 
    {
        $requestParameter = $request->getParsedBody();
        if (!checkEmptyParameter(array('id'),$request,$response)) 
        {  
            $userId = $db->getUserId();
            $toUserId = $requestParameter['id'];
            if ($db->checkUserById($toUserId)) 
            {  
                if ($db->checkFollowing($userId,$toUserId)) 
                {
                    $result = $db->doUnFollow($userId,$toUserId);
                    if ($result==UNFOLLOWED) 
                    {
                        $user = array();
                        $user['followersCount'] = $db->getFollowersCountById($toUserId);
                        returnUserResponse(false,"UnFollowed",$user,$response);
                    }
                    else if ($result==UNFOLLOW_FAILED) 
                    {
                        returnException(true,"Failed To Unfollow",$response);
                    }
                }
                else
                {
                    returnException(true,"Already Unfollowed",$response);
                }
            }
            else
            {
                returnException(true,"User Not Found",$response);
            }
        }
    }
});

$app->post('/login', function(Request $request, Response $response)
{
    if(!checkEmptyParameter(array('email','password'),$request,$response))
    {
        $db = new DbHandler;
        $requestParameter = $request->getParsedBody();
        $email = $requestParameter['email'];
        $password = $requestParameter['password'];
        $result = $db->login($email,$password);

        if($result ==LOGIN_SUCCESSFULL)
        {
            $user = $db->getUserByEmail($email);
            $user['token'] = getToken($user['id']);
            $user['feedsCount'] = $db->getFeedsCountById($user['id']);
            $user['followersCount'] = $db->getFollowersCountById($user['id']);
            $user['followingsCount'] = $db->getFollowingsCountById($user['id']);
            $responseUserDetails = array();
            $responseUserDetails['error'] = false;
            $responseUserDetails['message'] = "Login Successfull";
            $responseUserDetails['user'] = $user;
            $response->write(json_encode($responseUserDetails));
            return $response->withHeader('Content-type', 'application/json')
                     ->withStatus(200);

        }
        else if($result ==USER_NOT_FOUND)
        {
            returnException(true,"Email Is Not Registered",$response);
        }
        else if($result ==PASSWORD_WRONG)
        {
            returnException(true,"Wrong Password",$response);
        }
        else if($result ==UNVERIFIED_EMAIL)
        {
            returnException(true,"Email Is Not Verified",$response);
        }
        else if($result == EMAIL_NOT_VALID)
        {
            $email = $db->getEmailByUsername($email);
            if (empty($email)) 
            {
                returnException(true,"Email or Username is Wrong",$response);             
            }
            else
            {
                $user = $db->getUserByEmail($email);
                $user['token'] = getToken($user['id']);
                $user['feedsCount'] = $db->getFeedsCountById($user['id']);
                $user['followersCount'] = $db->getFollowersCountById($user['id']);
                $user['followingsCount'] = $db->getFollowingsCountById($user['id']);
                $responseUserDetails = array();
                $responseUserDetails['error'] = false;
                $responseUserDetails['message'] = "Login Successfull";
                $responseUserDetails['user'] = $user;
                $response->write(json_encode($responseUserDetails));
                return $response->withHeader('Content-type', 'application/json')
                     ->withStatus(200);            }
        }
        else
        {
            returnException(true,"Something Went Wrong",$response);
        }
    }
});

$app->get('/feed/{feedId}/comments', function (Request $request, Response $response, array $args)
{
    $db = new DbHandler;
    if (validateToken($db,$request,$response)) 
    {
        if (!empty($args['feedId'])) 
        {
            $userId = $db->getUserId();
            $feedId = $args['feedId'];
            if ($db->isFeedExist($feedId)) 
            {
                $comments = $db->getCommentsByFeedId($feedId);
                if (!empty($comments)) 
                {
                    $responseCommentDetails = array();
                    $responseCommentDetails['error'] = false;
                    $responseCommentDetails['message'] = "Comments Found";
                    $responseCommentDetails['comments'] = $comments;
                    $response->write(json_encode($responseCommentDetails));
                    return $response->withHeader('Content-type','application/json')
                                    ->withStatus(200);
                }
                else
                {
                    returnException(true,"No Comment Found",$response);
                }
            }
            else
            {
                returnException(true,"Feed Not Found",$response);
            }
        }
        else
        {
            returnException(true,"Invalid Request...! Feed Id must not be empty",$response);
        }
    }
});

$app->get('/user/{username}', function(Request $request, Response $response, array $args)
{
    $db = new DbHandler;
    if(validateToken($db,$request,$response))
    {
        $tokenId = $db->getUserId();
        $username = $args['username'];
        if ($db->isUsernameExist($username)) 
        {
            $id = $db->getUserIdByUsername($username);
            $user = $db->getUserById($id);
            $user['feedsCount'] = $db->getFeedsCountById($id);
            $user['followersCount'] = $db->getFollowersCountById($id);
            $user['followingsCount'] = $db->getFollowingsCountById($id);
            $user['following'] = $db->checkFollowing($tokenId,$id);
            $responseUserDetails = array();
            $responseUserDetails['error'] = false;
            $responseUserDetails['message'] = "User Found";
            $responseUserDetails['user'] = $user;
            $response->write(json_encode($responseUserDetails));
            return $response->withHeader('Content-type', 'application/json')
                     ->withStatus(200);
        }
        else
        {
            returnException(true,"Username Not Exist",$response);
        }
    }
});

$app->get('/user', function(Request $request, Response $response, array $args)
{
    $db = new DbHandler;
    if(validateToken($db,$request,$response))
    {
        $tokenId = $db->getUserId();
        if ($db->checkUserById($tokenId)) 
        {
            $user = $db->getUserById($tokenId);
            $user['feedsCount'] = $db->getFeedsCountById($tokenId);
            $user['followersCount'] = $db->getFollowersCountById($tokenId);
            $user['followingsCount'] = $db->getFollowingsCountById($tokenId);
            $responseUserDetails = array();
            $responseUserDetails['error'] = false;
            $responseUserDetails['message'] = "User Found";
            $responseUserDetails['user'] = $user;
            $response->write(json_encode($responseUserDetails));
            return $response->withHeader('Content-Type','application/json')
                            ->withStatus(200);
        }
        else
        {
            returnException(true,"Username Not Exist",$response);
        }
    }
});

$app->post('/updateUser', function(Request $request, Response $response)
{
    $db = new DbHandler;
    if (validateToken($db,$request,$response)) 
    {
        if (!checkEmptyParameter(array('name','username','bio'),$request,$response)) 
        {
            $requestParameter = $request->getParsedBody();
            $requestParameters = $request->getUploadedFiles();
            $name = $requestParameter['name'];
            $username = $requestParameter['username'];
            $bio = $requestParameter['bio'];
            $userId = $db->getUserId();
            if (empty($requestParameters['image'])) 
            {
                $image = $db->getImageById($userId);
            }
            else
            {
                $image = $requestParameters['image'];
            }
            if (empty($bio))
            {
                $bio = "This user is lazy. So they didn't written any bio.";
            }
            $result = $db->updateUser($userId,$name,$username,$bio,$image);
            if ($result==USER_UPDATED) 
            {
                $user = $db->getUserById($userId);
                $user['feedsCount'] = $db->getFeedsCountById($userId);
                $user['followersCount'] = $db->getFollowersCountById($userId);
                $user['followingsCount'] = $db->getFollowingsCountById($userId);
                $responseUserDetails = array();
                $responseUserDetails['error'] = false;
                $responseUserDetails['message'] = "User Updated";
                $responseUserDetails['user'] = $user;
                $response->write(json_encode($responseUserDetails));
                return $response->withHeader('Content-Type','application/json')
                                ->withStatus(200);
                }
            else if ($result==USER_UPDATE_FAILED) 
            {
                returnException(true,"Oops...! Failed To To Update User",$response);
            }
        }
    } 
});

$app->post('/postFeed', function(Request $request, Response $response)
{
    $db = new DbHandler;
    if (validateToken($db,$request,$response)) 
    {
        $requestParameters = $request->getUploadedFiles();
        $requestParameter = $request->getParsedBody();
        $id = $db->getUserId();
        if (empty($requestParameters['image'])) 
        {
            if(!empty($requestParameter['content']))
            {
                $image = null;
                $content = $requestParameter['content'];
                $result = $db->postFeed($id,$content,$image);
                if ($result==FEED_POSTED) 
                {
                    returnException(false,"Feed Has Been Posted",$response);
                }
                else if ($result==FEED_POST_FAILED) 
                {
                    returnException(true,"Oops...! Failed To Post Your Feed",$response);
                }
            }
            else
            {
                returnException(true,"Can't Post Empty Feed",$response);
            }
        }
        else
        {
            $image = $requestParameters['image'];
            if (!empty($requestParameter['content'])) 
            {
                $content = $requestParameter['content'];
                $result = $db->postFeed($id,$content,$image);
                if ($result==FEED_POSTED) 
                {
                    returnException(false,"Feed Has Been Posted",$response);
                }
                else if ($result==FEED_POST_FAILED) 
                {
                    returnException(true,"Oops...! Failed To Post Your Feed",$response);
                }
            }
            else
            {
                $content = "";
                $result = $db->postFeed($id,$content,$image);
                if ($result==FEED_POSTED) 
                {
                    returnException(false,"Feed Has Been Posted",$response);
                }
                else if ($result==FEED_POST_FAILED) 
                {
                    returnException(true,"Oops...! Failed To Post Your Feed",$response);
                }
                }
        }
    }
});

$app->post('/updateFeed', function (Request $request, Response $response)
{
    $db = new DbHandler;
    if (validateToken($db,$request,$response)) 
    {
        $id = $db->getUserId();
        $requestParameter = $request->getParsedBody();
        $requestParameters = $request->getUploadedFiles();
        if (empty($requestParameters['image'])) 
        {
            if(!empty($requestParameter['content']))
            {
                $image = null;
                $content = $requestParameter['content'];
                $result = $db->updateFeed($id,$content,$image);
                if ($result==USER_UPDATED) 
                {
                    returnException(false,"Feed Has Been Updated",$response);
                }
                else if ($result==USER_UPDATE_FAILED) 
                {
                    returnException(true,"Oops...! Failed To Update Your Feed",$response);
                }
            }
            else
            {
                returnException(true,"Can't Update Empty Feed",$response);
            }
        }
        else
        {
            $image = $requestParameters['image'];
            if (!empty($requestParameter['content'])) 
            {
                $content = $requestParameter['content'];
                $result = $db->updateFeed($id,$content,$image);
                if ($result==USER_UPDATED) 
                {
                    returnException(false,"Feed Has Been Updated",$response);
                }
                else if ($result==USER_UPDATE_FAILED) 
                {
                    returnException(true,"Oops...! Failed To Update Your Feed",$response);
                }
            }
            else
            {
                $content = "";
                $result = $db->updateFeed($id,$content,$image);
                if ($result==USER_UPDATED) 
                {
                    returnException(false,"Feed Has Been Updated",$response);
                }
                else if ($result==USER_UPDATE_FAILED) 
                {
                    returnException(true,"Oops...! Failed To Updated Your Feed",$response);
                }
                }
        }
    }
});

$app->get('/feeds', function(Request $request, Response $response)
{
    $db = new DbHandler;
    if (validateToken($db,$request,$response)) 
    {
        $feeds = $db->getFeeds();
        if (!empty($feeds)) 
        {
            $responseFeedDetails = array();
            $responseFeedDetails['error'] = false;
            $responseFeedDetails['message'] = "Feed List Found";
            $responseFeedDetails['feeds'] = $feeds;
            $response->write(json_encode($responseFeedDetails));
            return $response->withHeader('Content-Type','application/json')
                            ->withStatus(200);
        }
        else
        {
            returnException(true,"Feeds Not Found",$response);
        }
    }
});

$app->post('/deleteFeed', function(Request $request, Response $response)
{
    $db = new DbHandler;
    if (validateToken($db,$request,$response)) 
    {
        if (!checkEmptyParameter(array('id'),$request,$response)) 
        {   
            $requestParameter = $request->getParsedBody();
            $feedId = $requestParameter['id'];
            if ($db->isFeedExist($feedId)) 
            {
                $userId = $db->getUserId();
                if ($db->isFeedAuthor($feedId,$userId)) 
                {
                    $result = $db->deleteFeed($feedId,$userId);
                    if ($result== FEED_DELETED) 
                    {
                        returnException(false,"Feed Deleted",$response);
                    }
                    else if($result == FEED_DELETE_FAILED)
                    {
                        returnException(true,"Failed To Delete Feed", $response);
                    }
                }
                else
                {
                    returnException(true,"WARNING..! STOP..! You can delete only your own Feeds",$response);
                }
            }
            else
            {
                returnException(true,"Feed Not Found",$response);
            }
        }
    }
});

$app->get('/{username}/feeds', function(Request $request, Response $response, array $args)
{
    $db = new DbHandler;
    if (validateToken($db,$request,$response)) 
    {
        $username = $args['username'];
        $id = $db->getUserIdByUsername($username);
        if (!empty($id)) 
        {
            $feeds = $db->getFeedsByUserId($id);
            if (!empty($feeds)) 
            { 
                $responseFeedDetails = array();
                $responseFeedDetails['error'] = false;
                $responseFeedDetails['message'] = "Feed List Found";
                $responseFeedDetails['feeds'] = $feeds;
                $response->write(json_encode($responseFeedDetails));
                return $response->withHeader('Content-Type','application/json')
                                ->withStatus(200);  

            }
            else
            {
                returnException(true,"No Feed Found",$response);
            }
        }
        else
        {
            returnException(true,"User Not Found",$response);
        }
    }
});

$app->get('/feed/{feedId}', function(Request $request, Response $response,array $args)
{
    $db = new DbHandler;
    if (validateToken($db,$request,$response)) 
    {
        $feedId = $args['feedId'];
        if ($db->isFeedExist($feedId)) 
        {
            $feed = $db->getFeedById($feedId);
            if (!empty($feed)) 
            {
                $users = $db->getUserById($feed['userId']);
                $feed['userId']         =    $users['id'];
                $feed['userName']       =    $users['name'];
                $feed['userImage']      =    $users['image'];
                $feed['liked']          =    $db->checkFeedLike($feed['userId'],$feed['feedId']);
                $feed['feedLikes']      =    $db->getLikesCountByFeedId($feed['feedId']);
                $feed['feedComments']   =    $db->getCommentsCountByFeedId($feed['feedId']);
                $responseFeedDetails = array();
                $responseFeedDetails['error'] = false;
                $responseFeedDetails['message'] = "Feed List Found";
                $responseFeedDetails['feed'] = $feed;
                $response->write(json_encode($responseFeedDetails));
                return $response->withHeader('Content-Type','application/json')
                                ->withStatus(200);
            }
            else
            {
                returnException(true,"Feed Not Found",$response);
            }
        }
        else
        {
            returnException(true,"Feed Not Found",$response);
        }
    }
});

$app->post('/likeFeed', function(Request $request, Response $response)
{
    $db = new DbHandler;
    if (validateToken($db,$request,$response)) 
    {
        if (!checkEmptyParameter(array('feedId'),$request,$response)) 
        {   
            $requestParameter = $request->getParsedBody();
            $feedId = $requestParameter['feedId'];
            if ($db->isFeedExist($feedId)) 
            {
                $userId = $db->getUserId();
                if (!$db->isFeedLiked($feedId,$userId)) {
                    $result = $db->likeFeed($feedId,$userId);
                    if ($result== FEED_LIKED) 
                    { 
                        $feed = array();
                        $feed['feedLikes'] = $db->getLikesCountByFeedId($feedId);
                        $responseFeedDetails = array();
                        $responseFeedDetails['error'] = false;
                        $responseFeedDetails['message'] = "Feed Liked";
                        $responseFeedDetails['feed'] = $feed;
                        $response->write(json_encode($responseFeedDetails));
                        return $response->withHeader('Content-Type','application/json')
                                        ->withStatus(200);
                    }
                    else
                    {
                        returnException(true,"Failed To Like Feed", $response);
                    }
                }
                else
                {
                    returnException(true,"Feed Already Liked", $response);
                }
            }
            else
            {
                returnException(true,"Feed Not Found",$response);
            }
        }
    }
});

$app->post('/unlikeFeed', function(Request $request, Response $response)
{
    $db = new DbHandler;
    if (validateToken($db,$request,$response)) 
    {
        if (!checkEmptyParameter(array('feedId'),$request,$response)) 
        {   
            $requestParameter = $request->getParsedBody();
            $feedId = $requestParameter['feedId'];
            if ($db->isFeedExist($feedId)) 
            {
                $userId = $db->getUserId();
                if ($db->isFeedLiked($feedId,$userId)) 
                {
                    $result = $db->unlikeFeed($feedId,$userId);
                    if ($result== FEED_UNLIKED) 
                    {
                        $feed = array();
                        $feed['feedLikes'] = $db->getLikesCountByFeedId($feedId);
                        $responseFeedDetails = array();
                        $responseFeedDetails['error'] = false;
                        $responseFeedDetails['message'] = "Feed Unliked";
                        $responseFeedDetails['feed'] = $feed;
                        $response->write(json_encode($responseFeedDetails));
                        return $response->withHeader('Content-Type','application/json')
                                        ->withStatus(200);
                    }
                    else
                    {
                        returnException(true,"Failed To Unlike Feed", $response);
                    }
                }
                else
                {
                    returnException(true,"Feed Already Unliked",$response);
                }
            }
            else
            {
                returnException(true,"Feed Not Found",$response);
            }
        }
    }
});

$app->post('/postFeedComment', function(Request $request, Response $response)
{
    $db = new DbHandler;
    if (validateToken($db,$request,$response)) 
    {
        if (!checkEmptyParameter(array('feedId','comment'),$request,$response)) 
        {   
            $requestParameter = $request->getParsedBody();
            $feedId = $requestParameter['feedId'];
            $feedComment = $requestParameter['comment'];
            if ($db->isFeedExist($feedId)) 
            {
                $userId = $db->getUserId();
                $result = $db->addFeedComment($feedId,$feedComment,$userId);
                if ($result== FEED_COMMENT_ADDED) 
                {
                    returnException(false,"Comment Added",$response);
                }
                else if($result == FEED_COMMENT_ADD_FAILED)
                {
                    returnException(true,"Failed To Add Comment", $response);
                }
            }
            else
            {
                returnException(true,"Feed Not Found",$response);
            }
        }
    }
});

$app->post('/deleteFeedComment', function(Request $request, Response $response)
{
    $db = new DbHandler;
    if (validateToken($db,$request,$response)) 
    {
        if (!checkEmptyParameter(array('id'),$request,$response)) 
        {   
            $requestParameter = $request->getParsedBody();
            $commentId = $requestParameter['id'];
            if ($db->isCommentExist($commentId)) 
            {
                $userId = $db->getUserId();
                if ($db->isCommentAuthor($commentId,$userId)) 
                {
                    $result = $db->deleteFeedComment($commentId,$userId);
                    if ($result== FEED_COMMENT_DELETED) 
                    {
                        returnException(false,"Comment Deleted",$response);
                    }
                    else if($result == FEED_COMMENT_DELETE_FAILED)
                    {
                        returnException(true,"Failed To Delete Comment", $response);
                    }
                }
                else
                {
                    returnException(true,"WARNING..! STOP..! You can delete only your own comments",$response);
                }
            }
            else
            {
                returnException(true,"Comment Not Found",$response);
            }
        }
    }
});

$app->post('/sendEmailVerfication',function(Request $request, Response $response)
{
    $result = array(); 
    if(!checkEmptyParameter(array('email'),$request,$response))
    {
        $db = new DbHandler();
        $requestParameter = $request->getParsedBody();
        $email = $requestParameter['email'];
        $result = $db->sendEmailVerificationAgain($email);
        if($result ==SEND_CODE)
        {
            $name = $db->getNameByEmail($email);
            $code = $db->getCodeByEmail($email);
            $process = prepareVerificationMail($name,$email,$code);
            if($process)
            {
                returnException(false,"An Email Verification Link Has Been Sent Your Email Address: ".$email,$response);
            }
            else
            {
                returnException(true,"Failed To Sent Verification Email",$response);
            }
        }
        else if($result ==USER_NOT_FOUND)
        {
            returnException(true,"No Account Registered With This Email",$response);
        }
        else if($result == EMAIL_NOT_VALID)
        {
            returnException(true,"Enter Valid Email",$response);
        }
        else if($result ==EMAIL_ALREADY_VERIFIED)
        {
            returnException(true,"Your Email Address Already Verified",$response);
        }
        else
        {
            returnException(true,"Something Went Wrong",$response);
        }
    }
});

$app->get('/verifyEmail/{email}/{code}',function(Request $request, Response $response, array $args)
{
    $email = $args['email']; 
    $email = decrypt($email);
    $code = $args['code'];
    $db = new DbHandler();
    $result = array();
    $result = $db->verfiyEmail($email,$code);

    if($result == EMAIL_VERIFIED)
    {
        returnException(false,"Email Has Been Verified",$response);
    }
    else if($result ==EMAIL_NOT_VERIFIED)
    {
        returnException(true,"Failed To Verify Email",$response);
    }
    else if($result ==INVAILID_USER)
    {
        returnException(true,"INVALID USER",$response);
    }
    else if($result ==INVALID_VERFICATION_CODE)
    {
        returnException(true,"INVALID VERIFCATION CODE",$response);
    }
    else if($result ==EMAIL_ALREADY_VERIFIED)
    {
        returnException(true,"Your Email Is Already Verified",$response);
    }
    else
    {
        returnException(true,"Something Went Wrong",$response);
    }
});

$app->post('/forgotPassword', function(Request $request, Response $response)
{
    if(!checkEmptyParameter(array('email'),$request,$response))
    {
        $db = new DbHandler;
        $requestParameter = $request->getParsedBody();
        $email= $requestParameter['email'];
        $result = $db->forgotPassword($email);
        if($result == CODE_UPDATED)
        {
            $name = $db->getNameByEmail($email);
            $code = decrypt($db->getCodeByEmail($email));
            if(prepareForgotPasswordMail($name,$email,$code))
            {
                returnException(false,"OTP has been sent to your email address",$response);
            }
            returnException(true,"Failed To Send OTP Email",$response);
        }
        else if($result == EMAIL_NOT_VALID)
        {
            returnException(true,"Enter Valid Email",$response);
        }       
        else if($result ==USER_NOT_FOUND)
        {
            returnException(true,"Email Is Not Registered",$response);
        }
        else if($result ==EMAIL_NOT_VERIFIED)
        {
            returnException(true,"Email Is Not Verified",$response);
        }
        else if($result ==CODE_UPDATE_FAILED)
        {
            returnException(true,"Oops...! Some Error Occurred During Updating Code Into Database",$response);
        }
        else
        {
            returnException(true,"Oops...! Something Went Wrong.",$response);
        }
    }
});

$app->post('/resetPassword', function(Request $request, Response $response)
{
    $result = array();
    if(!checkEmptyParameter(array('email','otp','newPassword'),$request,$response))
    {
        $db = new DbHandler;
        $requestParameter = $request->getParsedBody();
        $email = $requestParameter['email'];
        $otp = $requestParameter['otp'];
        $newPassword = $requestParameter['newPassword'];
        $result = $db->resetPassword($email,$otp,$newPassword);

        if($result == PASSWORD_RESET)
        {
            $name = $db->getNameByEmail($email);
            preparePasswordChangedMail($name,$email);
            returnException(false,"Password Has Been Changed",$response);
        }
        else if($result == EMAIL_NOT_VALID)
        {
            returnException(true,"Enter Valid Email",$response);
        }       
        else if($result ==USER_NOT_FOUND)
        {
            returnException(true,"Email Is Not Registered",$response);
        }
        else if($result ==EMAIL_NOT_VERIFIED)
        {
            returnException(true,"Email Is Not Verified",$response);
        }
        else if($result ==PASSWORD_RESET_FAILED)
        {
            returnException(true,"Oops...! Some Error Occurred During Reseting Password",$response);
        }
        else if($result ==CODE_WRONG)
        {
            returnException(true,"Invalid Otp",$response);
        }
        else
        {
            returnException(true,"Oops...! Something Went Wrong.",$response);
        }


    }
});

$app->post('/updatePassword',function(Request $request, Response $response)
{
    $db = new DbHandler;
    if (validateToken($db,$request,$response)) 
    {
        if(!checkEmptyParameter(array('password','newpassword'),$request,$response))
            {
                $requestParameter = $request->getParsedBody();
                $password = $requestParameter['password'];
                $newPassword = $requestParameter['newpassword'];
                $id = $db->getUserId();
                $result = $db->updatePassword($id,$password,$newPassword);
                if($result ==PASSWORD_WRONG)
                {
                    returnException(true,"Wrong Password",$response);
                }
                else if($result==PASSWORD_CHANGED)
                {
                    $email = $db->getEmailById($id);
                    $name = $db->getNameByEmail($email);
                    preparePasswordChangedMail($name,$email);
                    returnException(false,"Password Has Been Updated",$response);
                }
                else if($result ==PASSWORD_CHANGE_FAILED)
                {
                    returnException(true,"Oops..! Something Went Wrong, Password Not Changed",$response);
                }
                else
                {
                    returnException(true,"Oops...! Something Went Wrong.",$response);
                }
            }
    }
});

$app->post('/uploadProfileImage', function(Request $request, Response $response)
{
    $db = new DbHandler;
    if (validateToken($db,$request,$response)) 
    {   
        $requestParameter = $request->getUploadedFiles();
        if (!empty($requestParameter['image'])) 
        {
            $image = $requestParameter['image'];
            $id = $db->getUserId();
            $result = $db->uploadProfileImage($id,$image);
            if($result == IMAGE_UPLOADED)
                {
                    returnException(false,"Image Uploaded",$response);
                }        
                else if($result ==IMAGE_UPLOADE_FAILED)
                {
                    returnException(true,"Failed To Upload The Image",$response);
                }
                else if($result ==IMAGE_NOT_SELECTED)
                {
                    returnException(true,"Image Not Selected",$response);
                }
        }
        else
        {
            returnException(true,"Image Not Selected",$response);
        }
    }
});

$app->get('/users', function(Request $request, Response $response)
{
    $db = new DbHandler;
    if (validateToken($db,$request,$response)) 
    {
            $id = $db->getUserId();
            $users = $db->getUsers($id);
            if (!empty($users)) 
            {
                $responseUserDetails = array();
                $responseUserDetails['error'] = false;
                $responseUserDetails['message'] = "Users List Found";
                $responseUserDetails['users'] = $users;
                $response->write(json_encode($responseUserDetails));
                return $response->withHeader('Content-type', 'application/json')
                         ->withStatus(200);
            }
            else
            {
                returnException(true,"No User Found",$response);
            }
    }
});

function checkEmptyParameter($requiredParameter,$request,$response)
{
    $result = array();
    $error = false;
    $errorParam = '';
    $requestParameter = $request->getParsedBody();
    foreach($requiredParameter as $param)
    {
        if(!isset($requestParameter[$param]) || strlen($requestParameter[$param])<1)
        {
            $error = true;
            $errorParam .= $param.', ';
        }
    }
    if($error)
    {
        returnException(true,"Required Parameter ".substr($errorParam,0,-2)." is missing",$response);
    }
    return $error;
}

function prepareForgotPasswordMail($name,$email,$code)
{
    $websiteDomain = WEBSITE_DOMAIN;
    $websiteName = WEBSITE_NAME;
    $websiteEmail = WEBSITE_EMAIL;
    $websiteOwnerName = WEBSITE_OWNER_NAME;
    $ipAddress = "(".$_SERVER['REMOTE_ADDR'].")";
    $mailSubject = "Recover your $websiteName password";
    $mailBody= <<<HERE
    <body style="background-color: #f4f4f4; margin: 0 !important; padding: 0 !important;">
    <!-- HIDDEN PREHEADER TEXT -->
    <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <!-- LOGO -->
        <tr>
            <td bgcolor="#FFA73B" align="center">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px;">
                    <tr>
                        <td align="center" valign="top" style="padding: 40px 10px 40px 10px;"> </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td bgcolor="#FFA73B" align="center" style="padding: 0px 10px 0px 10px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px;">
                    <tr>
                        <td bgcolor="#ffffff" align="center" valign="top" style="padding: 40px 20px 20px 20px; border-radius: 4px 4px 0px 0px; color: #111111; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 48px; font-weight: 400; letter-spacing: 4px; line-height: 48px;">
                            <h1 style="font-size: 48px; font-weight: 400; margin: 2;">Welcome!</h1><img src=" https://img.icons8.com/clouds/100/000000/handshake.png" width="125" height="120" style="display: block; border: 0px;" />
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td bgcolor="#f4f4f4" align="center" style="padding: 0px 10px 0px 10px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px;">
                    <tr>
                        <td bgcolor="#ffffff" align="left" style="padding: 20px 30px 40px 30px; color: #666666; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 18px; font-weight: 400; line-height: 25px;">
                            <p style="margin: 0;">You told us you forgot your password, If you really did, Use this OTP (One Time Password) to choose a new one.</p>
                        </td>
                    </tr>
                    <tr>
                        <td bgcolor="#ffffff" align="left">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td bgcolor="#ffffff" align="center" style="padding: 20px 30px 60px 30px;">
                                        <table border="0" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td align="center" style="border-radius: 3px;" bgcolor="#FFA73B"><b style="font-size: 20px; font-family: Helvetica, Arial, sans-serif; color: #ffffff; text-decoration: none; color: #ffffff; text-decoration: none; padding: 15px 25px; border-radius: 2px; border: 1px solid #FFA73B; display: inline-block;">$code</b></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr> <!-- COPY -->
                    <tr>
                        <td bgcolor="#ffffff" align="left" style="padding: 0px 30px 0px 30px; color: #666666; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 18px; font-weight: 400; line-height: 25px;">
                            <p style="margin: 0;">For security, this request was recieved from ip address $ipAddress. <br> If you didn't make this request, you can safely ignore this email :)</p>
                        </td>
                    </tr> <!-- COPY -->
                    <tr>
                        <td bgcolor="#ffffff" align="left" style="padding: 0px 30px 20px 30px; color: #666666; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 15px; font-weight: 400; line-height: 25px;">
                           <br> <p style="margin: 0;">If you have any questions, just reply to this email—we're always happy to help out.</p>
                        </td>
                    </tr>
                    <tr>
                        <td bgcolor="#ffffff" align="left" style="padding: 0px 30px 40px 30px; border-radius: 0px 0px 4px 4px; color: #666666; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 18px; font-weight: 400; line-height: 25px;">
                            <p style="margin: 0;">$websiteOwnerName,<br>$websiteName Team</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td bgcolor="#f4f4f4" align="center" style="padding: 30px 10px 0px 10px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px;">
                    <tr>
                        <td bgcolor="#FFECD1" align="center" style="padding: 30px 30px 30px 30px; border-radius: 4px 4px 4px 4px; color: #666666; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 18px; font-weight: 400; line-height: 25px;">
                            <h2 style="font-size: 20px; font-weight: 400; color: #111111; margin: 0;">Need more help?</h2>
                            <p style="margin: 0;"><a href="$websiteDomain" target="_blank" style="color: #FFA73B;">We&rsquo;re here to help you out</a></p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    </body>
    HERE;;

    if(sendMail($name,$email,$mailSubject,$mailBody))
    {
        return true;
    }
    return false;
}

function prepareVerificationMail($name,$email,$code)
{
    $emailEncrypted = encrypt($email);
    $websiteDomain = WEBSITE_DOMAIN;
    $websiteName = WEBSITE_NAME;
    $websiteEmail = WEBSITE_EMAIL;
    $websiteOwnerName = WEBSITE_OWNER_NAME;
    $endPoint = "/verifyEmail/";
    $mailSubject="Verify Your Email Address For $websiteName";
    $mailBody= <<<HERE
    <body style="background-color: #f4f4f4; margin: 0 !important; padding: 0 !important;">
    <!-- HIDDEN PREHEADER TEXT -->
    <div style="display: none; font-size: 1px; color: #fefefe; line-height: 1px; font-family: 'Lato', Helvetica, Arial, sans-serif; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden;"> We're thrilled to have you here! Get ready to dive into your new account. </div>
    <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <!-- LOGO -->
        <tr>
            <td bgcolor="#FFA73B" align="center">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px;">
                    <tr>
                        <td align="center" valign="top" style="padding: 40px 10px 40px 10px;"> </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td bgcolor="#FFA73B" align="center" style="padding: 0px 10px 0px 10px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px;">
                    <tr>
                        <td bgcolor="#ffffff" align="center" valign="top" style="padding: 40px 20px 20px 20px; border-radius: 4px 4px 0px 0px; color: #111111; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 48px; font-weight: 400; letter-spacing: 4px; line-height: 48px;">
                            <h1 style="font-size: 48px; font-weight: 400; margin: 2;">Welcome!</h1><img src=" https://img.icons8.com/clouds/100/000000/handshake.png" width="125" height="120" style="display: block; border: 0px;" />
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td bgcolor="#f4f4f4" align="center" style="padding: 0px 10px 0px 10px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px;">
                    <tr>
                        <td bgcolor="#ffffff" align="left" style="padding: 20px 30px 40px 30px; color: #666666; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 18px; font-weight: 400; line-height: 25px;">
                            <p style="margin: 0;">We're excited to have you get started. First, you need to confirm your account. Just press the button below.</p>
                        </td>
                    </tr>
                    <tr>
                        <td bgcolor="#ffffff" align="left">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td bgcolor="#ffffff" align="center" style="padding: 20px 30px 60px 30px;">
                                        <table border="0" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td align="center" style="border-radius: 3px;" bgcolor="#FFA73B"><a href="$websiteDomain$endPoint$emailEncrypted/$code" target="_blank" style="font-size: 20px; font-family: Helvetica, Arial, sans-serif; color: #ffffff; text-decoration: none; color: #ffffff; text-decoration: none; padding: 15px 25px; border-radius: 2px; border: 1px solid #FFA73B; display: inline-block;">Confirm Account</a></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr> <!-- COPY -->
                    <tr>
                        <td bgcolor="#ffffff" align="left" style="padding: 0px 30px 0px 30px; color: #666666; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 18px; font-weight: 400; line-height: 25px;">
                            <p style="margin: 0;">If that doesn't work, copy and paste the following link in your browser:</p>
                        </td>
                    </tr> <!-- COPY -->
                    <tr>
                        <td bgcolor="#ffffff" align="left" style="padding: 20px 30px 20px 30px; color: #666666; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 18px; font-weight: 400; line-height: 25px;">
                            <p style="margin: 0;"><a href="#" target="_blank" style="color: #FFA73B;">$websiteDomain$endPoint$emailEncrypted/$code</a></p>
                        </td>
                    </tr>
                    <tr>
                        <td bgcolor="#ffffff" align="left" style="padding: 0px 30px 20px 30px; color: #666666; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 18px; font-weight: 400; line-height: 25px;">
                            <p style="margin: 0;">If you have any questions, just reply to this email—we're always happy to help out.</p>
                        </td>
                    </tr>
                    <tr>
                        <td bgcolor="#ffffff" align="left" style="padding: 0px 30px 40px 30px; border-radius: 0px 0px 4px 4px; color: #666666; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 18px; font-weight: 400; line-height: 25px;">
                            <p style="margin: 0;">$websiteOwnerName,<br>$websiteName Team</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td bgcolor="#f4f4f4" align="center" style="padding: 30px 10px 0px 10px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px;">
                    <tr>
                        <td bgcolor="#FFECD1" align="center" style="padding: 30px 30px 30px 30px; border-radius: 4px 4px 4px 4px; color: #666666; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 18px; font-weight: 400; line-height: 25px;">
                            <h2 style="font-size: 20px; font-weight: 400; color: #111111; margin: 0;">Need more help?</h2>
                            <p style="margin: 0;"><a href="$websiteDomain" target="_blank" style="color: #FFA73B;">We&rsquo;re here to help you out</a></p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    </body>
    HERE;;
    if(sendMail($name,$email,$mailSubject,$mailBody))
    {
        return true;
    }
    return false;
}

function preparePasswordChangedMail($name,$email)
{
    $websiteDomain = WEBSITE_DOMAIN;
    $websiteName = WEBSITE_NAME;
    $websiteEmail = WEBSITE_EMAIL;
    $websiteOwnerName = WEBSITE_OWNER_NAME;
    $ipAddress = "(".$_SERVER['REMOTE_ADDR'].")";
    date_default_timezone_set('Asia/Kolkata');
    $currentDate = date('d');
    $currentMonth =  DateTime::createFromFormat('!m',date('m'));
    $currentMonth = $currentMonth->format('F');
    $currentYear = date('yy');
    $currentTime = date('h:i a');
    $mailSubject = "Your password has been changed.";
    $mailBody = <<<HERE
    <body style="background-color: #f4f4f4; margin: 0 !important; padding: 0 !important;">
    <!-- HIDDEN PREHEADER TEXT -->
    <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <!-- LOGO -->
        <tr>
            <td bgcolor="#FFA73B" align="center">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px;">
                    <tr>
                        <td align="center" valign="top" style="padding: 40px 10px 40px 10px;"> </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td bgcolor="#FFA73B" align="center" style="padding: 0px 10px 0px 10px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px;">
                    <tr>
                        <td bgcolor="#ffffff" align="center" valign="top" style="padding: 40px 20px 20px 20px; border-radius: 4px 4px 0px 0px; color: #111111; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 48px; font-weight: 400; letter-spacing: 4px; line-height: 48px;">
                            <h1 style="font-size: 48px; font-weight: 400; margin: 2;">Welcome!</h1><img src=" https://img.icons8.com/clouds/100/000000/handshake.png" width="125" height="120" style="display: block; border: 0px;" />
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td bgcolor="#f4f4f4" align="center" style="padding: 0px 10px 0px 10px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px;">
                    <tr>
                        <td bgcolor="#ffffff" align="left" style="padding: 20px 30px 40px 30px; color: #666666; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 18px; font-weight: 400; line-height: 25px;">
                            <p style="margin: 0;">This is a confirmation that you password was changed at $currentTime on $currentDate $currentMonth $currentYear</p>
                        </td>
                    </tr>
                    <tr>
                        <td bgcolor="#ffffff" align="left" style="padding: 0px 30px 0px 30px; color: #666666; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 18px; font-weight: 400; line-height: 25px;">
                            <p style="margin: 0;">For security, The password was changed from the Ip Address $ipAddress. If this was you, then you can safely ignore this email :)</p>
                        </td>
                    </tr> <!-- COPY -->
                    <tr>
                        <td bgcolor="#ffffff" align="left" style="padding: 0px 30px 20px 30px; color: #666666; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 15px; font-weight: 400; line-height: 25px;">
                           <br> <p style="margin: 0;">If you have any questions, just reply to this email—we're always happy to help out.</p>
                        </td>
                    </tr>
                    <tr>
                        <td bgcolor="#ffffff" align="left" style="padding: 0px 30px 40px 30px; border-radius: 0px 0px 4px 4px; color: #666666; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 18px; font-weight: 400; line-height: 25px;">
                            <p style="margin: 0;">$websiteOwnerName,<br>$websiteName Team</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td bgcolor="#f4f4f4" align="center" style="padding: 30px 10px 0px 10px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px;">
                    <tr>
                        <td bgcolor="#FFECD1" align="center" style="padding: 30px 30px 30px 30px; border-radius: 4px 4px 4px 4px; color: #666666; font-family: 'Lato', Helvetica, Arial, sans-serif; font-size: 18px; font-weight: 400; line-height: 25px;">
                            <h2 style="font-size: 20px; font-weight: 400; color: #111111; margin: 0;">Need more help?</h2>
                            <p style="margin: 0;"><a href="$websiteDomain" target="_blank" style="color: #FFA73B;">We&rsquo;re here to help you out</a></p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    </body>
    HERE;;
    sendMail($name,$email,$mailSubject,$mailBody);
}

function sendMail($name,$email,$mailSubject,$mailBody)
{
    $websiteEmail = WEBSITE_EMAIL;
    $websiteEmailPassword = WEBSITE_EMAIL_PASSWORD;
    $websiteName = WEBSITE_NAME;
    $websiteOwnerName = WEBSITE_OWNER_NAME;
    $mail = new PHPMailer;
    $mail->SMTPDebug = 0;
    $mail->isSMTP();
    $mail->Host="smtp.gmail.com";
    $mail->Port=587;
    $mail->SMPTSecure="tls";
    $mail->SMTPAuth=true;
    $mail->Username = $websiteEmail;
    $mail->Password = $websiteEmailPassword;
    $mail->addAddress($email,$name);
    $mail->isHTML();
    $mail->Subject=$mailSubject;
    $mail->Body=$mailBody;
    $mail->From=$websiteEmail;
    $mail->FromName=$websiteName;
    if($mail->send())
    {
        return true;
    }
    return false;
}

function encrypt($data)
{
    $email = openssl_encrypt($data,"AES-128-ECB",null);
    $email = str_replace('/','socialcodia',$email);
    $email = str_replace('+','mufazmi',$email);
    return $email; 
}

function decrypt($data)
{
    $mufazmi = str_replace('mufazmi','+',$data);
    $email = str_replace('socialcodia','/',$mufazmi);
    $email = openssl_decrypt($email,"AES-128-ECB",null);
    return $email; 
}

function returnException($error,$message,$response)
{
    $errorDetails = array();
    $errorDetails['error'] = $error;
    $errorDetails['message'] = $message;
    $response->write(json_encode($errorDetails));
    return $response->withHeader('Content-Type','application/json')
                    ->withStatus(200);
}

function returnFeedResponse($error,$message,$feedResponse,$response)
{
    $responseFeedDetails = array();
    $responseFeedDetails['error'] = $error;
    $responseFeedDetails['message'] = $message;
    $responseFeedDetails['feed'] = $feedResponse;
    $response->write(json_encode($responseFeedDetails));
    return $response->withHeader('Content-Type','application/json')
                    ->withStatus(200);
}

function returnUserResponse($error,$message,$userResponse,$response)
{
    $responseUserDetails = array();
    $responseUserDetails['error'] = $error;
    $responseUserDetails['message'] = $message;
    $responseUserDetails['user'] = $userResponse;
    $response->write(json_encode($responseUserDetails));
    return $response->withHeader('Content-type', 'application/json')
             ->withStatus(200);
}


function getToken($userId)
{
    $key = JWT_SECRET_KEY;
    $payload = array(
        "iss" => "socialcodia.net",
        "iat" => time(),
        "user_id" => $userId
    );
    $token =JWT::encode($payload,$key);
    return $token;
}

function validateToken($db,$request,$response)
{
    $error = false;
    $header =$request->getHeaders();
    if (!empty($header['HTTP_TOKEN'][0])) 
    {
        $token = $header['HTTP_TOKEN'][0];
        $result = $db->validateToken($token);
        if (!$result == JWT_TOKEN_FINE) 
        {
            $error = true;
        }
        else if($result == JWT_TOKEN_ERROR || $result==JWT_USER_NOT_FOUND)
        {
            returnException(true,"Token Error...! Please Login Again",$response);
            $error = true;
        }
    }

    else
    {
        returnException(true,"Invalid Token, Please Login Again",$response);
        $error = true;
    }
    if ($error) 
    {
        return false;
    }
    else
    {
        return true;
    }
}

$app->run();