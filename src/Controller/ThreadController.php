<?php

/*
 * This file is part of the FOSCommentBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FOS\CommentBundle\Controller;

use FOS\CommentBundle\Model\CommentInterface;
use FOS\CommentBundle\Model\ThreadInterface;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\View\View;
use FOS\RestBundle\View\ViewHandlerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Restful controller for the Threads.
 *
 * @author Alexander <iam.asm89@gmail.com>
 */
class ThreadController extends AbstractFOSRestController
{
    const ERROR = "Thread with id '%s' could not be found.";
    const VIEW_FLAT = 'flat';
    const VIEW_TREE = 'tree';

    public function newThreadsAction(Request $request): Response
    {
        $form = $this->container->get('fos_comment.form_factory.thread')->createForm();

        $view = View::create()
            ->setData([
                'data' => [
                    'form' => $form->createView(),
                ],
                'template' => '@FOSComment/Thread/new.html.twig',
            ]);

        return $this->handleView($view);
    }

    public function getThreadAction(Request $request, string $id): Response
    {

        $manager = $this->container->get('fos_comment.manager.thread');
        $thread = $manager->findThreadById($id);

        if (null === $thread) {
            throw new NotFoundHttpException(sprintf(self::ERROR, $id));
        }

        $view = View::create()
            ->setData(['thread' => $thread]);

        return $this->handleView($view);
    }

    public function getThreadsActions(Request $request): Response
    {
        $ids = $request->query->get('ids');

        if (null === $ids) {
            throw new NotFoundHttpException('Cannot query threads without id\'s.');
        }

        $threads = $this->container->get('fos_comment.manager.thread')->findThreadsBy(['id' => $ids]);

        $view = View::create()
            ->setData(['threads' => $threads]);

        return $this->handleView($view);
    }

    public function postThreadsAction(Request $request): Response
    {
        $threadManager = $this->container->get('fos_comment.manager.thread');
        $thread = $threadManager->createThread();
        $form = $this->container->get('fos_comment.form_factory.thread')->createForm();
        $form->setData($thread);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (null !== $threadManager->findThreadById($thread->getId())) {
                $this->onCreateThreadErrorDuplicate($form);
            }

            // Add the thread
            $threadManager->saveThread($thread);

            return $this->handleView($this->onCreateThreadSuccess($form));
        }

        return $this->handleView($this->onCreateThreadError($form));
    }

    public function editThreadCommentableAction(Request $request, string $id): Response
    {
        $manager = $this->container->get('fos_comment.manager.thread');
        $thread = $manager->findThreadById($id);

        if (null === $thread) {
            throw new NotFoundHttpException(sprintf(self::ERROR, $id));
        }

        $thread->setCommentable($request->query->get('value', 1));

        $form = $this->container->get('fos_comment.form_factory.commentable_thread')->createForm();
        $form->setData($thread);

        $view = View::create()
            ->setData([
                    'data' => [
                        'form' => $form,
                        'id' => $id,
                        'isCommentable' => $thread->isCommentable(),
                    ],
                    'template' => '@FOSComment/Thread/commentable.html.twig',
                ]
            );

        return $this->handleView($view);
    }

    public function patchThreadCommentableAction(Request $request, string $id): Response
    {
        $manager = $this->container->get('fos_comment.manager.thread');
        $thread = $manager->findThreadById($id);

        if (null === $thread) {
            throw new NotFoundHttpException(sprintf(self::ERROR, $id));
        }

        $form = $this->container->get('fos_comment.form_factory.commentable_thread')->createForm();
        $form->setData($thread);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $manager->saveThread($thread);

            return $this->handleView($this->onOpenThreadSuccess($form));
        }

        return $this->handleView($this->onOpenThreadError($form));
    }

    public function newThreadCommentsAction(Request $request, string $id): Response
    {
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        if (!$thread) {
            throw new NotFoundHttpException(sprintf('Thread with identifier of "%s" does not exist', $id));
        }

        $comment = $this->container->get('fos_comment.manager.comment')->createComment($thread);

        $parent = $this->getValidCommentParent($thread, $request->query->get('parentId'));

        $form = $this->container->get('fos_comment.form_factory.comment')->createForm();
        $form->setData($comment);

        $view = View::create()
            ->setData([
                    'data' => [
                        'form' => $form->createView(),
                        'first' => 0 === $thread->getNumComments(),
                        'thread' => $thread,
                        'parent' => $parent,
                        'id' => $id,
                    ],
                    'template' => '@FOSComment/Thread/comment_new.html.twig',
                ]
            );

        return $this->handleView($view);
    }

    public function getThreadCommentAction(Request $request, string $id, ?string $commentId): Response
    {
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        $comment = $this->container->get('fos_comment.manager.comment')->findCommentById($commentId);
        $parent = null;

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(
                sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id)
            );
        }

        $ancestors = $comment->getAncestors();
        if (count($ancestors) > 0) {
            $parent = $this->getValidCommentParent($thread, $ancestors[count($ancestors) - 1]);
        }

        $view = View::create()
            ->setData([
                    'data' => [
                        'comment' => $comment,
                        'thread' => $thread,
                        'parent' => $parent,
                        'depth' => $comment->getDepth(),
                    ],
                    'template' => '@FOSComment/Thread/comment.html.twig',
                ]
            );

        return $this->handleView($view);
    }

    public function removeThreadCommentAction(Request $request, string $id, ?string $commentId): Response
    {
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        $comment = $this->container->get('fos_comment.manager.comment')->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(
                sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id)
            );
        }

        $form = $this->container->get('fos_comment.form_factory.delete_comment')->createForm();
        $comment->setState($request->query->get('value', $comment::STATE_DELETED));

        $form->setData($comment);

        $view = View::create()
            ->setData([
                    'data' => [
                        'form' => $form,
                        'id' => $id,
                        'commentId' => $commentId,
                    ],
                    'template' => '@FOSComment/Thread/comment_remove.html.twig',
                ]
            );

        return $this->handleView($view);
    }

    public function patchThreadCommentStateAction(Request $request, string $id, ?string $commentId): Response
    {
        $manager = $this->container->get('fos_comment.manager.comment');
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        $comment = $manager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $form = $this->container->get('fos_comment.form_factory.delete_comment')->createForm();
        $form->setData($comment);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (false !== $manager->saveComment($comment)) {
                return $this->getViewHandler()->handle($this->onRemoveThreadCommentSuccess($form, $id));
            }
        }

        return $this->handleView($this->onRemoveThreadCommentError($form, $id));
    }

    /**
     * Presents the form to use to edit a Comment for a Thread.
     *
     * @param string $id Id of the thread
     * @param mixed $commentId Id of the comment
     *
     * @return Response
     */
    public function editThreadCommentAction(Request $request, string $id, ?string $commentId): Response
    {
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        $comment = $this->container->get('fos_comment.manager.comment')->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(
                sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id)
            );
        }

        $form = $this->container->get('fos_comment.form_factory.comment')->createForm(null, ['method' => 'PUT']);
        $form->setData($comment);

        $view = View::create()
            ->setData([
                    'data' => [
                        'form' => $form->createView(),
                        'comment' => $comment,
                    ],
                    'template' => '@FOSComment/Thread/comment_edit.html.twig',
                ]
            );

        return $this->handleView($view);
    }

    public function putThreadCommentsAction(Request $request, string $id, ?string $commentId): Response
    {
        $commentManager = $this->container->get('fos_comment.manager.comment');

        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        $comment = $commentManager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(
                sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id)
            );
        }

        $form = $this->container->get('fos_comment.form_factory.comment')->createForm(null, ['method' => 'PUT']);
        $form->setData($comment);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (false !== $commentManager->saveComment($comment)) {
                return $this->getViewHandler()->handle($this->onEditCommentSuccess($form, $id, $comment->getParent()));
            }
        }

        return $this->handleView($this->onEditCommentError($form, $id, $comment->getParent()));
    }

    public function getThreadCommentsAction(Request $request, string $id): Response
    {
        $displayDepth = $request->query->get('displayDepth');
        $sorter = $request->query->get('sorter');
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);

        // We're now sure it is no duplicate id, so create the thread
        if (null === $thread) {
            $permalink = $request->query->get('permalink');

            $thread = $this->container->get('fos_comment.manager.thread')
                ->createThread();
            $thread->setId($id);
            $thread->setPermalink($permalink);

            // Validate the entity
            $errors = $this->get('validator')->validate($thread, null, ['NewThread']);
            if (count($errors) > 0) {
                $view = View::create()
                    ->setStatusCode(Response::HTTP_BAD_REQUEST)
                    ->setData([
                            'data' => [
                                'errors' => $errors,
                            ],
                            'template' => '@FOSComment/Thread/errors.html.twig',
                        ]
                    );

                return $this->handleView($view);
            }

            // Decode the permalink for cleaner storage (it is encoded on the client side)
            $thread->setPermalink(urldecode($permalink));

            // Add the thread
            $this->container->get('fos_comment.manager.thread')->saveThread($thread);
        }

        $viewMode = $request->query->get('view', 'tree');
        switch ($viewMode) {
            case self::VIEW_FLAT:
                $comments = $this->container->get('fos_comment.manager.comment')->findCommentsByThread($thread, $displayDepth, $sorter);

                // We need nodes for the api to return a consistent response, not an array of comments
                $comments = array_map(function ($comment) {
                    return ['comment' => $comment, 'children' => []];
                },
                    $comments
                );
                break;
            case self::VIEW_TREE:
            default:
                $comments = $this->container->get('fos_comment.manager.comment')->findCommentTreeByThread($thread, $sorter, $displayDepth);
                break;
        }

        $view = View::create()
            ->setData([
                    'data' => [
                        'comments' => $comments,
                        'displayDepth' => $displayDepth,
                        'sorter' => 'date',
                        'thread' => $thread,
                        'view' => $viewMode,
                    ],
                    'template' => '@FOSComment/Thread/comments.html.twig',
                ]
            );

        // Register a special handler for RSS. Only available on this route.
        if ('rss' === $request->getRequestFormat()) {
            $templatingHandler = function ($handler, $view, $request) {
                $data = $view->getData();
                $data['template'] = '@FOSComment/Thread/thread_xml_feed.html.twig';

                $view->setData($data);

                return new Response($handler->renderTemplate($view, 'rss'), Response::HTTP_OK, $view->getHeaders());
            };

            $this->get('fos_rest.view_handler')->registerHandler('rss', $templatingHandler);
        }

        return $this->handleView($view);
    }

    public function postThreadCommentsAction(Request $request, string $id): Response
    {
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        if (!$thread) {
            throw new NotFoundHttpException(sprintf('Thread with identifier of "%s" does not exist', $id));
        }

        if (!$thread->isCommentable()) {
            throw new AccessDeniedHttpException(sprintf('Thread "%s" is not commentable', $id));
        }

        $parent = $this->getValidCommentParent($thread, $request->query->get('parentId'));
        $commentManager = $this->container->get('fos_comment.manager.comment');
        $comment = $commentManager->createComment($thread, $parent);

        $form = $this->container->get('fos_comment.form_factory.comment')->createForm(null, ['method' => 'POST']);
        $form->setData($comment);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (false !== $commentManager->saveComment($comment)) {
                return $this->handleView($this->onCreateCommentSuccess($form, $id, $parent));
            }
        }

        return $this->handleView($this->onCreateCommentError($form, $id, $parent));
    }

    public function getThreadCommentVotesAction(Request $request, string $id, ?string $commentId): Response
    {
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        $comment = $this->container->get('fos_comment.manager.comment')->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(
                sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id)
            );
        }

        $view = View::create()
            ->setData([
                    'data' => [
                        'commentScore' => $comment->getScore(),
                    ],
                    'template' => '@FOSComment/Thread/comment_votes.html.twig',
                ]
            );

        return $this->handleView($view);
    }


    public function newThreadCommentVotesAction(Request $request, string $id, ?string $commentId): Response
    {
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        $comment = $this->container->get('fos_comment.manager.comment')->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(
                sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id)
            );
        }

        $vote = $this->container->get('fos_comment.manager.vote')->createVote($comment);
        $vote->setValue($request->query->get('value', 1));

        $form = $this->container->get('fos_comment.form_factory.vote')->createForm();
        $form->setData($vote);

        $view = View::create()
            ->setData([
                    'data' => [
                        'id' => $id,
                        'commentId' => $commentId,
                        'form' => $form->createView(),
                    ],
                    'template' => '@FOSComment/Thread/vote_new.html.twig',
                ]
            );

        return $this->handleView($view);
    }


    public function postThreadCommentVotesAction(Request $request, string $id, ?string $commentId): Response
    {
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        $comment = $this->container->get('fos_comment.manager.comment')->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(
                sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id)
            );
        }

        $voteManager = $this->container->get('fos_comment.manager.vote');
        $vote = $voteManager->createVote($comment);

        $form = $this->container->get('fos_comment.form_factory.vote')->createForm();
        $form->setData($vote);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $voteManager->saveVote($vote);

            return $this->handleView($this->onCreateVoteSuccess($form, $id, $commentId));
        }

        return $this->handleView($this->onCreateVoteError($form, $id, $commentId));
    }

    protected function onCreateCommentSuccess(FormInterface $form, string $id, CommentInterface $parent = null): View
    {
        return View::createRouteRedirect(
            'fos_comment_get_thread_comment',
            ['id' => $id, 'commentId' => $form->getData()->getId()],
            Response::HTTP_CREATED
        );
    }

    protected function onCreateCommentError(FormInterface $form, string $id, CommentInterface $parent = null): View
    {
        return View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                    'data' => [
                        'form' => $form,
                        'id' => $id,
                        'parent' => $parent,
                    ],
                    'template' => '@FOSComment/Thread/comment_new.html.twig',
                ]
            );
    }

    protected function onCreateThreadSuccess(FormInterface $form): View
    {
        return View::createRouteRedirect(
            'fos_comment_get_thread',
            ['id' => $form->getData()->getId()],
            Response::HTTP_CREATED
        );
    }

    /**
     * Returns a HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface $form
     *
     * @return View
     */
    protected function onCreateThreadError(FormInterface $form): View
    {
        return View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                    'data' => [
                        'form' => $form,
                    ],
                    'template' => '@FOSComment/Thread/new.html.twig',
                ]
            );
    }

    protected function onCreateThreadErrorDuplicate(FormInterface $form): Response
    {
        return new Response(
            sprintf("Duplicate thread id '%s'.", $form->getData()->getId()),
            Response::HTTP_BAD_REQUEST
        );
    }

    protected function onCreateVoteSuccess(FormInterface $form, string $id, ?string $commentId): View
    {
        return View::createRouteRedirect(
            'fos_comment_get_thread_comment_votes',
            ['id' => $id, 'commentId' => $commentId],
            Response::HTTP_CREATED
        );
    }

    protected function onCreateVoteError(FormInterface $form, string $id, ?string $commentId): View
    {
        return View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                    'data' => [
                        'id' => $id,
                        'commentId' => $commentId,
                        'form' => $form,
                    ],
                    'template' => '@FOSComment/Thread/vote_new.html.twig',
                ]
            );
    }

    protected function onEditCommentSuccess(FormInterface $form, string $id): View
    {
        return View::createRouteRedirect(
            'fos_comment_get_thread_comment',
            ['id' => $id, 'commentId' => $form->getData()->getId()],
            Response::HTTP_CREATED
        );
    }

    protected function onEditCommentError(FormInterface $form, string $id): View
    {
        return View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                    'data' => [
                        'form' => $form,
                        'comment' => $form->getData(),
                    ],
                    'template' => '@FOSComment/Thread/comment_edit.html.twig',
                ]
            );
    }

    protected function onOpenThreadSuccess(FormInterface $form): View
    {
        return View::createRouteRedirect(
            'fos_comment_edit_thread_commentable',
            ['id' => $form->getData()->getId(), 'value' => !$form->getData()->isCommentable()],
            Response::HTTP_CREATED
        );
    }

    protected function onOpenThreadError(FormInterface $form): View
    {
        return View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                    'data' => [
                        'form' => $form,
                        'id' => $form->getData()->getId(),
                        'isCommentable' => $form->getData()->isCommentable(),
                    ],
                    'template' => '@FOSComment/Thread/commentable.html.twig',
                ]
            );
    }

    protected function onRemoveThreadCommentSuccess(FormInterface $form, string $id): View
    {
        return View::createRouteRedirect(
            'fos_comment_get_thread_comment',
            ['id' => $id, 'commentId' => $form->getData()->getId()],
            Response::HTTP_CREATED
        );
    }

    protected function onRemoveThreadCommentError(FormInterface $form, string $id): View
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                    'data' => [
                        'form' => $form,
                        'id' => $id,
                        'commentId' => $form->getData()->getId(),
                        'value' => $form->getData()->getState(),
                    ],
                    'template' => '@FOSComment/Thread/comment_remove.html.twig',
                ]
            );

        return $view;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function getValidCommentParent(ThreadInterface $thread, ?string $commentId): ?CommentInterface
    {
        if (null !== $commentId) {
            $comment = $this->container->get('fos_comment.manager.comment')->findCommentById($commentId);
            if (!$comment) {
                throw new NotFoundHttpException(sprintf('Parent comment with identifier "%s" does not exist', $commentId));
            }

            if ($comment->getThread() !== $thread) {
                throw new NotFoundHttpException('Parent comment is not a comment of the given thread.');
            }

            return $comment;
        }
        return null;
    }

    protected function getViewHandler(): ViewHandlerInterface
    {
        $viewHandler = parent::getViewHandler();
        $viewHandler->registerHandler('html', function($element, View $view, $request, $format) {
            return $view->getResponse();
        });
        return $viewHandler;
    }
}
