<?php

/**
 * CommentController provides all comment related actions.
 *
 * @package humhub.modules_core.comment.controllers
 * @since 0.5
 */
class CommentController extends Controller
{

    // Used by loadTargetModel() to avoid multiple loading
    private $cachedLoadedTarget = null;

    /**
     * @return array action filters
     */
    public function filters()
    {
        return array(
            'accessControl', // perform access control for CRUD operations
        );
    }

    /**
     * Specifies the access control rules.
     * This method is used by the 'accessControl' filter.
     * @return array access control rules
     */
    public function accessRules()
    {
        return array(
            array('allow', // allow authenticated user to perform 'create' and 'update' actions
                'users' => array('@'),
            ),
            array('deny', // deny all users
                'users' => array('*'),
            ),
        );
    }

    /**
     * Loads Target Model for the Comment
     * It needs to be a SiContentBehavior Object
     *
     * @return type
     */
    private function loadTargetModel()
    {

        // Fast lane
        if ($this->cachedLoadedTarget != null)
            return $this->cachedLoadedTarget;

        // Request Params
        $targetModelClass = Yii::app()->request->getParam('model');
        $targetModelId = (int) Yii::app()->request->getParam('id');

        $targetModelClass = Yii::app()->input->stripClean(trim($targetModelClass));

        if ($targetModelClass == "" || $targetModelId == "") {
            throw new CHttpException(500, Yii::t('CommentModule.controllers_CommentController', 'Model & Id Parameter required!'));
        }

        if (!Helpers::CheckClassType($targetModelClass, 'HActiveRecordContent')) {
            throw new CHttpException(500, Yii::t('CommentModule.controllers_CommentController', 'Invalid target class given'));
        }

        $model = call_user_func(array($targetModelClass, 'model'));
        $target = $model->findByPk($targetModelId);

        if (!$target instanceof HActiveRecordContent) {
            throw new CHttpException(500, Yii::t('CommentModule.controllers_CommentController', 'Invalid target class given'));
        }

        if ($target == null) {
            throw new CHttpException(404, Yii::t('CommentModule.controllers_CommentController', 'Target not found!'));
        }

        // Check if we can read the target model, so we can comment it?
        if (!$target->content->canRead(Yii::app()->user->id)) {
            throw new CHttpException(403, Yii::t('CommentModule.controllers_CommentController', 'Access denied!'));
        }

        // Create Fastlane:
        $this->cachedLoadedTarget = $target;

        return $target;
    }

    /**
     * Returns a List of all Comments belong to this Model
     */
    public function actionShow($isLimited, $shownCommentCount = Comment::DEFAULT_SHOWN_COMMENT_COUNT)
    {

        $target = $this->loadTargetModel();

        $output = $this->widget('application.modules_core.comment.widgets.CommentsWidget', array('object' => $target, 'isLimited' => $isLimited, 'shownCommentCount' => $shownCommentCount), true);

        Yii::app()->clientScript->render($output);
        echo $output;
        Yii::app()->end();
    }

    public function actionShowPopup()
    {

        $target = $this->loadTargetModel();

        $output = "";

        // Get new current comments
        $comments = Comment::model()->findAllByAttributes(array('object_model' => get_class($target), 'object_id' => $target->id));

        foreach ($comments as $comment) {
            $output .= $this->widget('application.modules_core.comment.widgets.ShowCommentWidget', array('comment' => $comment), true);
        }


        $id = get_class($target) . "_" . $target->id;
        $this->renderPartial('show', array('object' => $target, 'output' => $output, 'id' => $id), false, true);
    }

    /**
     * Handles AJAX Post Request to submit new Comment
     */
    public function actionPost()
    {

        $this->forcePostRequest();
        $target = $this->loadTargetModel();

        // Get the visibility of the old comments from redis.
        $isLimitedCacheId = 'is_limited_'.get_class($target).'_'.$target->id;
        $isLimited = Yii::app()->redis->getClient()->get($isLimitedCacheId);

        $shownCommentCount = Comment::DEFAULT_SHOWN_COMMENT_COUNT;

        $message = Yii::app()->request->getParam('message', "");
        $message = Yii::app()->input->stripClean(trim($message));

        if ($message != "") {

            $comment = new Comment;
            $comment->message = $message;
            $comment->object_model = get_class($target);
            $comment->object_id = $target->id;

            // Check if target has an attribute with space_id
            // When yes, take it
            // We need it for dashboard/getFrontEndInfo
            // To count workspace Items
            try {
                $comment->space_id = $target->content->space_id;

                $workspace = Space::model()->findByPk($comment->space_id);

                // Update Last viewed for Spaces
                if ($workspace != "") {
                    $membership = $workspace->getMembership(Yii::app()->user->id);
                    if ($membership != null) {
                        $membership->scenario = 'last_visit';
                        $membership->last_visit = new CDbExpression('NOW()');
                        $membership->save();
                    }
                }
            } catch (Exception $ex) {
                ;
            }

            $comment->save();
            $target->updated_at = new CDbExpression('NOW()');
	        $target->save();
            File::attachPrecreated($comment, Yii::app()->request->getParam('fileList'));

            $shownCommentCount = $this->increaseShownCommentCount($comment->object_model, $comment->object_id);
        }

        return $this->actionShow($isLimited, $shownCommentCount);
    }

    private function increaseShownCommentCount($modelName, $modelId)
    {
        $shownCommentCountCacheId = 'shown_comment_count_'.$modelName.'_'.$modelId;
        $shownCommentCount = Yii::app()->redis->getClient()->get($shownCommentCountCacheId);

        // Increasing shownCommentCount if new comment saved successfully.
        Yii::app()->redis->getClient()->set($shownCommentCountCacheId, ++$shownCommentCount, Comment::CACHE_TIMEOUT);

        return $shownCommentCount;
    }

    public function actionEdit()
    {
        $id = Yii::app()->request->getParam('id');
        $model = Comment::model()->findByPk($id);

        if ($model->canWrite()) {

            if (isset($_POST['Comment'])) {
                $_POST['Comment'] = Yii::app()->input->stripClean($_POST['Comment']);
                $model->attributes = $_POST['Comment'];
                if ($model->validate()) {
                    $model->save();

                    // Reload comment to get populated updated_at field
                    $model = Comment::model()->findByPk($id);

                    // Return the new comment
                    $output = $this->widget('application.modules_core.comment.widgets.ShowCommentWidget', array('comment' => $model, 'justEdited' => true), true);
                    Yii::app()->clientScript->render($output);
                    echo $output;
                    return;
                }
            }

            $this->renderPartial('edit', array('comment' => $model), false, true);
        } else {
            throw new CHttpException(403, Yii::t('CommentModule.controllers_CommentController', 'Access denied!'));
        }
    }

    /**
     * Handles AJAX Request for Comment Deletion.
     * Currently this is only allowed for the Comment Owner.
     */
    public function actionDelete()
    {

        $this->forcePostRequest();
        $target = $this->loadTargetModel();

        // Get the visibility of the old comments from redis.
        $isLimitedCacheId = 'is_limited_'.get_class($target).'_'.$target->id;
        $isLimited = Yii::app()->redis->getClient()->get($isLimitedCacheId);

        $commentId = (int) Yii::app()->request->getParam('cid', "");

        $comment = Comment::model()->findByPk($commentId);

        // Check if Comment correspond to the given Target (Access checking)
        if ($comment != null && $comment->object_model == get_class($target) && $comment->object_id == $target->id) {

            // Check if User can delete this Comment
            if ($comment->canDelete()) {
                $comment->delete();
            } else {
                throw new CHttpException(500, Yii::t('CommentModule.controllers_CommentController', 'Insufficent permissions!'));
            }
        } else {
            throw new CHttpException(500, Yii::t('CommentModule.controllers_CommentController', 'Could not delete comment!')); // Possible Hack attempt!
        }

        $shownCommentCountCacheId = 'shown_comment_count_' . get_class($target) . '_' . $target->id;
        $shownCommentCount = Yii::app()->redis->getClient()->get($shownCommentCountCacheId);

        return $this->actionShow($isLimited, $shownCommentCount);
    }

    public function actionApiCount()                                                                                                                                           
    {                                                                                                                                                                          
        $target = $this->loadTargetModel();                                                                                                                                    
        die(Comment::GetCommentCount(get_class($target), $target->id));                                                                                                        
    }   
    public function actionApiComment()                                                                                                                                           
    {                                                                                                                                                                          
        $target = $this->loadTargetModel();                                                                                                                                    
        $cid = (int) Yii::app()->request->getParam('cid', "");
        $comments = Comment::GetCommentsFrom(get_class($target), $target->id, 5, $cid);                                                                                                        
        $output = [];
        foreach ($comments as $comment) {
            $output[$comment->id] = $this->widget('application.modules_core.comment.widgets.ShowCommentWidget', array('comment' => $comment), true);
        }
        die(json_encode($output));
    }   


}
