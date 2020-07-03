<?php

require_once dirname(__FILE__).'/JWT.php';
    $JWT = new JWT;


class DbHandler
{
    private $con;
    private $userId;

    function __construct()
    {
        require_once dirname(__FILE__) . '/DbCon.php';
        $db = new DbCon;
        $this->con =  $db->Connect();
    }

    //Getter Setter For User Id Only

    function setUserId($userId)
    {
        $this->userId = $userId;
    }

    function getUserId()
    {
        return $this->userId;
    }

    function createUser($name,$username,$email,$password)
    {
        $user = array();
        if($this->isEmailValid($email))
        {
            if (!$this->isEmailExist($email))
            {
                if (!$this->isUsernameExist($username)) 
                {
                    $hashPass = password_hash($password,PASSWORD_DEFAULT);
                    $code = password_hash($email.time(),PASSWORD_DEFAULT);
                    $code = str_replace('/','socialcodia',$code);
                    $query = "INSERT INTO users (name,username,email,password,code,status) VALUES (?,?,?,?,?,?)";
                    $stmt = $this->con->prepare($query);
                    $status =0;
                    $stmt->bind_param('ssssss',$name,$username,$email,$hashPass,$code,$status);
                    if($stmt->execute())
                    {        
                        return USER_CREATED;
                    }
                    else
                    {
                        return FAILED_TO_CREATE_USER;
                    }
                }
                else
                {
                    return USERNAME_EXIST;
                }
            }
            else
            {
                return EMAIL_EXIST;
            }
        }
        return EMAIL_NOT_VALID;
    }

    function login($email,$password)
    {
        if($this->isEmailValid($email))
        {
            if($this->isEmailExist($email))
            {
                $hashPass = $this->getPasswordByEmail($email);
                if(password_verify($password,$hashPass))
                {
                    if($this->isEmailVerified($email))
                    {
                        return LOGIN_SUCCESSFULL;
                    }
                    else
                    {
                        return UNVERIFIED_EMAIL;
                    }
                }
                {
                    return PASSWORD_WRONG;
                }
            }
            else
            {
                return USER_NOT_FOUND;
            }
        }
        else
        {
            return EMAIL_NOT_VALID;
        }
    }

    function updateUser($id,$name,$username,$bio,$image)
    {
        $imageUrl = $this->uploadImage($image);
        $query = "UPDATE users SET name=?, username=?, bio=?, image=? WHERE id=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("sssss",$name,$username,$bio,$imageUrl,$id);
        if($stmt->execute())
        {
            return USER_UPDATED;
        }
        else
        {
            return USER_UPDATE_FAILED;
        }
    }

    function getUserImageById($id)
    {
        $query = "SELECT image FROM users WHERE id=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("s",$id);
        $stmt->execute();
        $stmt->bind_result($image);
        return $image;
    }

    function postFeed($id, $content, $image)
    {
        $imageUrl = $this->uploadImage($image);
        $query = "INSERT INTO feeds (userId,feedContent,feedImage) VALUES(?,?,?)";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("sss",$id,$content,$imageUrl);
        if ($stmt->execute()) 
        {
            return FEED_POSTED;
        }
        else
        {
            return FEED_POST_FAILED;
        }
    }

    function updateFeed($id,$content,$image)
    {
        $imageUrl = $this->uploadImage($image);
        $query = "UPDATE feeds SET content=?, image=? WHERE id=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("sss",$content,$imageUrl,$id);
        if($stmt->execute())
        {
            return FEED_UPDATED;
        }
        else
        {
            return FEED_UPDATE_FAILED;
        }
    }

    function deleteFeed($feedId,$userId)
    {
        $query = "DELETE FROM feeds WHERE feedId = ? AND userId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("ss",$feedId,$userId);
        if ($stmt->execute()) 
        {
            return FEED_DELETED;
        }
        else
        {
            return FEED_DELETE_FAILED;
        }
    }

    function getCommentsByFeedId($feedId)
    {
        $comments = array();
        $commentsData = array();
        $query = "SELECT commentId,userId,feedId,feedComment,timestamp FROM comments WHERE feedId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("s",$feedId);
        $stmt->execute();
        $stmt->bind_result($commentId,$userId,$feedId,$feedComment,$timestamp);
        while ($stmt->fetch()) 
        {
            $comment = array();
            $comment['commentId'] = $commentId ;
            $comment['userId'] = $userId ;
            $comment['feedId'] = $feedId ;
            $comment['feedComment'] = $feedComment ;
            $comment['timestamp'] = $timestamp ;
            array_push($comments, $comment);
        }
        foreach ($comments as $commentList) 
        {   
            $comment = array();
            $users = array();
            $userId = $this->getUserId();
            $id = $commentList['userId'];
            $users = $this->getUserById($id);
            $comment['userId']         =    $users['id'];
            $comment['userName']       =    $users['name'];
            $comment['userUsername']   =    $users['username'];
            $comment['userImage']      =    $users['image'];
            $comment['liked']          =    $this->checkCommentLike($userId,$commentList['commentId']);
            $comment['commentLikesCount']  =   $this->getCommentsLikeCountByCommentId($commentList['commentId']);
            $comment['commentId']      =       $commentList['commentId'];
            $comment['commentComment']    =       $commentList['feedComment'];
            $comment['commentTimestamp']  =    $commentList['timestamp'];
            array_push($commentsData, $comment);
        }
        return $commentsData;
    }

    function getCommentsLikeCountByCommentId($commentId)
    {
        $query = "SELECT commentLikeId FROM commentLikes WHERE commentLikeId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("s",$commentId);
        $stmt->execute();
        $stmt->store_result();
        return $stmt->num_rows;
    }

    function getFeeds()
    {
        $feeds = array();
        $feedsData = array();
        $query = "SELECT feedId, userId, feedImage, feedContent, timestamp FROM feeds order by timestamp desc";
        $stmt = $this->con->prepare($query);
        $stmt->execute();
        $stmt->bind_result($id,$userId,$image,$content,$timestamp);
        while ($stmt->fetch()) 
        {
            $feed = array();
            $feed['feedUserId'] = $userId;
            $feed['feedId'] = $id;
            $feed['feedImage'] = $image;
            $feed['feedContent'] = $content;
            $feed['feedTimestamp'] = $timestamp;
            array_push($feeds, $feed);
        }
        foreach ($feeds as $feedList) 
        {   
            $feed = array();
            $users = array();
            $userId = $this->getUserId();
            $id = $feedList['feedUserId'];
            $users = $this->getUserById($id);
            $feed['userId']         =    $users['id'];
            $feed['userName']       =    $users['name'];
            $feed['userUsername']   =    $users['username'];
            $feed['userImage']      =    $users['image'];
            $feed['feedId']         =    $feedList['feedId'];
            $feed['liked']          =    $this->checkFeedLike($userId,$feedList['feedId']);
            $feed['feedLikes']      =    $this->getLikesCountByFeedId($feedList['feedId']);
            $feed['feedComments']   =    $this->getCommentsCountByFeedId($feedList['feedId']);
            $feed['feedImage']      =    $feedList['feedImage'];
            $feed['feedContent']    =    $feedList['feedContent'];
            $feed['feedTimestamp']  =    $feedList['feedTimestamp'];
            array_push($feedsData, $feed);
        }
        return $feedsData;
    }

    function checkFeedLike($userId,$feedId)
    {
        $query = "SELECT likeId FROM likes WHERE userId=? AND feedId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("ss",$userId,$feedId);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows()>0) 
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    function checkCommentLike($userId,$commentId)
    {
        $query = "SELECT commentLikeId FROM commentLikes WHERE userId=? AND commentId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("ss",$userId,$commentId);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows()>0) 
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    function checkFollowing($tokenId,$id)
    {
        $query = "SELECT followsId FROM follows WHERE userId=? AND toUserId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("ss",$tokenId,$id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows()>0) 
        {
            return true;
        }
        else
        {
            return false;
        }
    }
    

    function getFeedById($feedId)
    {
        $feed = array();
        $feeds = array(); 
        $feedsData = array();
        $query = "SELECT feedId, userId, feedContent, feedImage, timestamp FROM feeds WHERE feedId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("s",$feedId);
        $stmt->execute();
        $stmt->bind_result($id,$userId,$content,$image,$timestamp);
        $stmt->fetch();
        $feed = array();
        $feed['feedId'] = $id;
        $feed['feedContent'] = $content;
        $feed['feedImage'] = $image;
        $feed['feedTimestamp'] = $timestamp;
        $feed['userId'] = $userId;
        return $feed;
    }

    function likeFeed($feedId, $userId)
    {
        $query = "INSERT INTO likes (feedId,userId) VALUES (?,?)";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("ss",$feedId,$userId);
        if ($stmt->execute()) 
        {
            return FEED_LIKED;
        }
        else
        {
            return FEED_LIKE_FAILED;
        }
    }

    function doFollow($userId, $id)
    {
        $query = "INSERT INTO follows (userId,toUserId) VALUES (?,?)";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("ss",$userId,$id);
        if ($stmt->execute()) 
        {
            return FOLLOWED;
        }
        else
        {
            return FOLLOW_FAILED;
        }
    }

    function doUnFollow($userId, $id)
    {
        $query = "DELETE FROM follows WHERE userId=? AND toUserId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("ss",$userId,$id);
        if ($stmt->execute()) 
        {
            return UNFOLLOWED;
        }
        else
        {
            return UNFOLLOW_FAILED;
        }
    }


    function unlikeFeed($feedId, $userId)
    {
        $query = "DELETE FROM likes WHERE feedId=? AND userId =?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("ss",$feedId,$userId);
        if ($stmt->execute()) 
        {
            return FEED_UNLIKED;
        }
        else
        {
            return FEED_UNLIKE_FAILED;
        }
    }

    function addFeedComment($feedId, $feedComment, $userId)
    {
        $query = "INSERT INTO comments (feedId,feedComment,userId) VALUES (?,?,?)";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("sss",$feedId,$feedComment,$userId);
        if ($stmt->execute()) 
        {
            return FEED_COMMENT_ADDED;
        }
        else
        {
            return FEED_COMMENT_ADD_FAILED;
        }
    }

    function deleteFeedComment($commentId, $userId)
    {
        $query = "DELETE FROM comments WHERE commentId = ? AND userId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("ss",$commentId,$userId);
        if ($stmt->execute()) 
        {
            return FEED_COMMENT_DELETED;
        }
        else
        {
            return FEED_COMMENT_DELETE_FAILED;
        }
    }


    function getFeedsByUserId($userId)
    {
        $feed = array();
        $feeds = array(); 
        $feedsData = array();
        $query = "SELECT feedId, userId, feedContent, feedImage, timestamp FROM feeds WHERE userId=? order by timestamp desc";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("s",$userId);
        $stmt->execute();
        $stmt->bind_result($id,$userId,$content,$image,$timestamp);
        while ($stmt->fetch()) 
        {
            $feed = array();
            $feed['feedId'] = $id;
            $feed['feedContent'] = $content;
            $feed['feedImage'] = $image;
            $feed['feedTimestamp'] = $timestamp;
            $feed['userId'] = $userId;
            array_push($feeds, $feed);
        }
        foreach ($feeds as $feedList) 
        {   
            $feed = array();
            $users = array();
            $users = $this->getUserById($userId);
            $feed['userId']         =    $users['id'];
            $feed['userName']       =    $users['name'];
            $feed['userUsername']   =    $users['username'];
            $feed['userImage']      =    $users['image'];
            $feed['feedId']         =    $feedList['feedId'];
            $feed['liked']          =    $this->checkFeedLike($userId,$feedList['feedId']);
            $feed['feedLikes']      =    $this->getLikesCountByFeedId($feedList['feedId']);
            $feed['feedComments']   =    $this->getCommentsCountByFeedId($feedList['feedId']);
            $feed['feedImage']      =    $feedList['feedImage'];
            $feed['feedContent']    =    $feedList['feedContent'];
            $feed['feedTimestamp']  =    $feedList['feedTimestamp'];
            array_push($feedsData, $feed);
        }
        return $feedsData;
    }

    function getUserById($id)
    {
        $query = "SELECT id,name,username,email,bio,image FROM users WHERE id=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('s',$id);
        $stmt->execute();
        $stmt->bind_result($id,$name,$username,$email,$bio,$image);
        $stmt->fetch();
        $user['id'] = $id;
        $user['name'] = $name;
        $user['username'] = $username;
        $user['email'] = $email;
        $user['bio'] = $bio;
        if (empty($image)) 
        {
            $image = DEFAULT_USER_IMAGE;
        }
        $user['image'] = $image;
        return $user;
    }

    function getUserIdByUsername($username)
    {
        $query = "SELECT id FROM users WHERE username=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('s',$username);
        $stmt->execute();
        $stmt->bind_result($id);
        $stmt->fetch();
        return $id;
    }

    function getLikesCountByFeedId($feedId)
    {
        $query = "SELECT * FROM likes WHERE feedId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("s",$feedId);
        $stmt->execute();
        $stmt->store_result();
        return $stmt->num_rows;
    }



    function getFeedsCountById($userId)
    {
        $query = "SELECT * FROM feeds WHERE userId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("s",$userId);
        $stmt->execute();
        $stmt->store_result();
        return $stmt->num_rows;
    }

    function getFollowersCountById($userId)
    {
        $query = "SELECT * FROM follows WHERE toUserId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("s",$userId);
        $stmt->execute();
        $stmt->store_result();
        return $stmt->num_rows;
    }

        function getFollowingsCountById($userId)
    {
        $query = "SELECT * FROM follows WHERE userId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("s",$userId);
        $stmt->execute();
        $stmt->store_result();
        return $stmt->num_rows;
    }

    function getCommentsCountByFeedId($feedId)
    {
        $query = "SELECT * FROM comments WHERE feedId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("s",$feedId);
        $stmt->execute();
        $stmt->store_result();
        return $stmt->num_rows;
    }

    function uploadImage($image)
    {
        $imageUrl ="";
        if ($image!=null) 
        {
            $imageName = $image->getClientFilename();
            $image = $image->file;
            $targetDir = "uploads/";
            $targetFile = $targetDir.uniqid().'.'.pathinfo($imageName,PATHINFO_EXTENSION);
            if (move_uploaded_file($image,$targetFile)) 
            {
                $imageUrl = WEBSITE_DOMAIN.$targetFile;
            }
        }
        return $imageUrl;
    }

    function uploadProfileImage($id,$image)
    {
        $imageName = $image->getClientFilename();
        $image = $image->file;
        if($image!=null)
        {
            $targetDir = "uploads/";
            // $targetFile = $targetDir.uniqid().'.'.pathinfo($image['name'],PATHINFO_EXTENSION);
            $targetFile = $targetDir.uniqid().'.'.pathinfo($imageName, PATHINFO_EXTENSION);
            if(move_uploaded_file($image,$targetFile))
            {
                $domain = WEBSITE_DOMAIN.$targetFile;
                $query = "UPDATE users set image=? WHERE id=? ";
                $stmt = $this->con->prepare($query);
                $stmt->bind_param('ss',$domain,$id);
                if($stmt->execute())
                {
                    return IMAGE_UPLOADED;
                }
                return IMAGE_UPLOADE_FAILED;
            }
            return IMAGE_UPLOADE_FAILED;
        }
        return IMAGE_NOT_SELECTED;
    }

    function updatePassword($id,$password, $newPassword)
    {

        $hashPass = $this->getPasswordById($id);
        if(password_verify($password,$hashPass))
        {
            $newHashPassword = password_hash($newPassword,PASSWORD_DEFAULT);
            $query = "UPDATE users SET password=? WHERE id=?";
            $stmt = $this->con->prepare($query);
            $stmt->bind_param('ss',$newHashPassword,$id);
            if($stmt->execute())
            {
                return PASSWORD_CHANGED;
            }
            return PASSWORD_CHANGE_FAILED;
        }
        return PASSWORD_WRONG;  
    }

    function forgotPassword($email)
    {
        $result = array();
        if($this->isEmailValid($email))
        {
            if($this->isEmailExist($email))
            {
                if($this->isEmailVerified($email))
                {
                    $code = rand(100000,999999);
                    $name = $this->getNameByEmail($email);
                    if($this->updateCode($email,$code))
                    {
                        return CODE_UPDATED;
                    }
                    return CODE_UPDATE_FAILED;
                }
                return EMAIL_NOT_VERIFIED;
            }
            return USER_NOT_FOUND;
        }
        return EMAIL_NOT_VALID;
    }

    function resetPassword($email,$code,$newPassword)
    {
        if($this->isEmailValid($email))
        {
            if($this->isEmailExist($email))
            {
                if($this->isEmailVerified($email))
                {
                    $hashCode = decrypt($this->getCodeByEmail($email));
                    if($code==$hashCode)
                    {
                        $hashPass = password_hash($newPassword,PASSWORD_DEFAULT);
                        $query = "UPDATE users SET password=? WHERE email=?";
                        $stmt = $this->con->prepare($query);
                        $stmt->bind_param('ss',$hashPass,$email);
                        if($stmt->execute())
                        {
                            $randCode = password_hash(rand(100000,999999),PASSWORD_DEFAULT);
                            $this->updateCode($email,$randCode);
                            return PASSWORD_RESET;
                        }
                        return PASSWORD_RESET_FAILED;
                    } 
                    return CODE_WRONG;
                }
                return EMAIL_NOT_VERIFIED;
            }
            return USER_NOT_FOUND;
        }
        return EMAIL_NOT_VALID;
    }

    function sendEmailVerificationAgain($email)
    {
        $result = array();
        if($this->isEmailValid($email))
        {
            if($this->isEmailExist($email))
            {
                if(!$this->isEmailVerified($email))
                {
                    $code = $this->getCodeByEmail($email);
                    $name = $this->getNameByEmail($email);
                    $result['code'] = $code;
                    $result['email'] = $email;
                    $result['name'] = $name;
                    return SEND_CODE;
                }
                return EMAIL_ALREADY_VERIFIED;
            }
            return USER_NOT_FOUND;
        }
        return EMAIL_NOT_VALID;
    }

    function updateCode($email,$code)
    {
        $hashCode = encrypt($code);
        $query = "UPDATE users SET code=? WHERE email=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('ss',$hashCode,$email);
        if($stmt->execute())
        {
            return true;
        }      
        return false;
    }

    function verfiyEmail($email,$code)
    {
        $result = array();
        if($this->isEmailExist($email))
        {
            $dbCode = $this->getCodeByEmail($email);
            if($dbCode==$code)
            { 
                if(!$this->isEmailVerified($email))
                {
                    $resp = $this->setEmailIsVerfied($email);
                    if($resp)
                    {
                        return EMAIL_VERIFIED;
                    }
                    return EMAIL_NOT_VERIFIED;
                }
                return EMAIL_ALREADY_VERIFIED;
            }
            return INVALID_VERFICATION_CODE;
        }
        return INVAILID_USER;
    }

    function isEmailExist($email)
    {
        $query = "SELECT id FROM users WHERE email=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('s',$email);
        $stmt->execute();
        $stmt->store_result();
        return $stmt->num_rows>0 ;
    }

    function isFeedExist($feedId)
    {
        $query = "SELECT feedId FROM feeds WHERE feedId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('s',$feedId);
        $stmt->execute();
        $stmt->store_result();
        return $stmt->num_rows>0 ;
    }

    function isFeedAuthor($feedId,$userId)
    {
        $query = "SELECT feedId FROM feeds WHERE feedId=? AND userId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('ss',$feedId,$userId);
        $stmt->execute();
        $stmt->store_result();
        return $stmt->num_rows>0 ;
    }

    function isCommentAuthor($commnetId,$userId)
    {
        $query = "SELECT feedId FROM comments WHERE commentId=? AND userId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param("ss",$commnetId,$userId);
        $stmt->execute();
        $stmt->store_result();
        return $stmt->num_rows>0;
    }

    function isCommentExist($commnetId)
    {
        $query = "SELECT commentId FROM comments WHERE commentId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('s',$commnetId);
        $stmt->execute();
        $stmt->store_result();
        return $stmt->num_rows>0 ;
    }

    function isFeedLiked($feedId,$userId)
    {
        $query = "SELECT likeId FROM likes WHERE feedId=? AND userId=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('ss',$feedId,$userId);
        $stmt->execute();
        $stmt->store_result();
        return $stmt->num_rows>0 ;
    }

    function isUsernameExist($username)
    {
        $query = "SELECT id FROM users WHERE username=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('s',$username);
        $stmt->execute();
        $stmt->store_result();
        return $stmt->num_rows>0 ;
    }

    function isEmailVerified($email)
    {
        $query = "SELECT status FROM users WHERE email=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('s',$email);
        $stmt->execute();
        $stmt->bind_result($status);
        $stmt->fetch();
        return $status;
    }

    function getPasswordByEmail($email)
    {
        $query = "SELECT password FROM users WHERE email=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('s',$email);
        $stmt->execute();
        $stmt->bind_result($password);
        $stmt->fetch();
        return $password;
    }

    function getImageById($userId)
    {
        $query = "SELECT image FROM users WHERE id=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('s',$id);
        $stmt->execute();
        $stmt->bind_result($image);
        $stmt->fetch();
        return $image;
    }

    function getPasswordById($id)
    {
        $query = "SELECT password FROM users WHERE id=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('s',$id);
        $stmt->execute();
        $stmt->bind_result($password);
        $stmt->fetch();
        return $password;
    }

    function getUsers($id)
    {
        $url = "SELECT id,name,username,email,image FROM users WHERE id !=? AND status != ?";
        $stmt = $this->con->prepare($url);
        $status = "0";
        $stmt->bind_param("ss",$id,$status);
        $stmt->execute();
        $stmt->bind_result($id,$name,$username,$email,$image);
        $users = array();
        while ($stmt->fetch()) {
            $user = array();
            $user['id'] = $id;
            $user['name'] = $name;
            $user['username'] = $username;
            $user['email'] = $email;
            if (empty($image)) 
            {
                $image = DEFAULT_USER_IMAGE;
            }
            $user['image'] = $image;
            array_push($users, $user);
        }
        return $users;
    }

    function checkUserById($id)
    {
        $query = "SELECT email FROM users WHERE id=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('s',$id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows>0) 
        {
            return true;
        }
        return false;
    }

    function getEmailById($id)
    {
        $query = "SELECT email FROM users WHERE id=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('s',$id);
        $stmt->execute();
        $stmt->bind_result($email);
        $stmt->fetch();
        return $email;
    }

    function getEmailByUsername($username)
    {
        $query = "SELECT email FROM users WHERE username=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('s',$username);
        $stmt->execute();
        $stmt->bind_result($email);
        $stmt->fetch();
        return $email;
    }

    function getNameByEmail($email)
    {
        $query = "SELECT name FROM users WHERE email=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('s',$email);
        $stmt->execute();
        $stmt->bind_result($name);
        $stmt->fetch();
        return $name;
    }

    function getCodeByEmail($email)
    {
        $query = "SELECT code FROM users WHERE email=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('s',$email);
        $stmt->execute();
        $stmt->bind_result($code);
        $stmt->fetch();
        return $code;
    }

    function setEmailIsVerfied($email)
    {
        $status = 1;
        $query = "UPDATE users SET status=? WHERE email =?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('ss',$status,$email);
        if($stmt->execute())
        {
            return true;
        }
        return false;
    }

    function getUserByEmail($email)
    {
        $query = "SELECT id,name,username,email,bio,image FROM users WHERE email=?";
        $stmt = $this->con->prepare($query);
        $stmt->bind_param('s',$email);
        $stmt->execute();
        $stmt->bind_result($id,$name,$username,$email,$bio,$image);
        $stmt->fetch();
        $user = array();
        $user['id'] = $id;
        $user['name'] = $name;
        $user['username'] = $username;
        $user['email'] = $email;
        $user['bio'] = $bio;
        if (empty($image)) 
        {
            $image = DEFAULT_USER_IMAGE;
        }
        $user['image'] = $image;
        return $user;
    }

    function isEmailValid($email)
    {
        if(filter_var($email,FILTER_VALIDATE_EMAIL))
        {
            return true;
        }
        return false;
    }

    function validateToken($token)
    {
        try 
        {
            $key = JWT_SECRET_KEY;
            $payload = JWT::decode($token,$key,['HS256']);
            $id = $payload->user_id;
            if ($this->checkUserById($id)) 
            {
                $this->setUserId($payload->user_id);
                return JWT_TOKEN_FINE;
            }
            return JWT_USER_NOT_FOUND;
        } 
        catch (Exception $e) 
        {
            return JWT_TOKEN_ERROR;    
        }
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

    
}