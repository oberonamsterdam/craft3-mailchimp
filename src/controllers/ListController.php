<?php


namespace white\craft\mailchimp\controllers;

use Craft;
use craft\web\Controller;
use craft\web\Request;
use Exception;
use Psr\Http\Message\ResponseInterface;
use white\craft\mailchimp\client\commands\lists\GetLists;
use white\craft\mailchimp\client\commands\lists\members\AddOrUpdateListMember;
use white\craft\mailchimp\client\commands\lists\members\GetListMember;
use white\craft\mailchimp\client\MailChimpException;
use white\craft\mailchimp\helpers\ListControllerRequestHelper;
use white\craft\mailchimp\MailChimpPlugin;
use yii\web\BadRequestHttpException;
use yii\web\HttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

class ListController extends Controller
{
    protected $allowAnonymous = ['subscribe', 'check-if-subscribed'];

    private function getClient()
    {
        return MailChimpPlugin::getInstance()->getClient();
    }

    /**
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionSubscribe()
    {
        $request = Craft::$app->getRequest();

        try {
            if (!$request->getIsPost()) {
                throw new MethodNotAllowedHttpException();
            }

            $requestHelper = new ListControllerRequestHelper();
            $listIds = $requestHelper->getListIds($request);
            $email = $this->getEmail($request);
            $memberData = $this->getMemberData($email, $request);
            $this->addOrUpdateListMembers($listIds, $email, $memberData);

            return $this->renderSuccessResponse(
                $request,
                ['success' => true],
                $message = Craft::t('mailchimp', 'Subscribed successfully.')
            );

        } catch (Exception $exception) {
            return $this->renderErrorResponse($request, $exception);
        }
    }

    /**
     * @param $request
     * @return mixed
     * @throws BadRequestHttpException
     */
    private function getEmail(Request $request)
    {
        $email = $request->getParam('email');

        if (!$email) {
            throw new BadRequestHttpException("Email address is not specified.");
        }

        return $email;
    }

    /**
     * @param $email
     * @param $request
     * @return array
     */
    private function getMemberData(string $email, Request $request): array
    {
        $memberData = [
            'email_address' => $email,
            'status_if_new' => $request->getValidatedBodyParam('status') ?? 'subscribed',
            'email_type' => $request->getParam('emailType', 'html'),
            'language' => $request->getParam('language', ''),
            'vip' => (bool)($request->getValidatedBodyParam('vip') ?? false),
        ];

        $vars = $request->getParam('vars');
        $interests = $request->getParam('interests');
        $location = $request->getParam('location');

        if (!empty($vars) && is_array($vars)) {
            $memberData['merge_fields'] = array_map('strval', $vars);
        }

        if (!empty($interests) && is_array($interests)) {
            $memberData['interests'] = array_map('boolval', $interests);
        }

        if (isset($location['latitude']) && isset($location['longitude'])) {
            $memberData['location'] = [
                'latitude' => (float)$location['latitude'],
                'longitude' => (float)$location['longitude'],
            ];
        }

        return $memberData;
    }

    /**
     * @param string $listIds
     * @param string $email
     * @param array $memberData
     */
    private function addOrUpdateListMembers(string $listIds, string $email, array $memberData): void
    {
        foreach (explode(',', $listIds) as $listId) {
            if (empty($listId)) {
                continue;
            }

            $this->getClient()->send(new AddOrUpdateListMember($listId, $email, $memberData));
        }
    }

    /**
     * @param Request $request
     * @param $data
     * @param string $message
     * @return Response
     * @throws BadRequestHttpException
     */
    private function renderSuccessResponse(Request $request, $data, string $message): Response
    {
        if ($request->getIsAjax()) {
            $response = $this->asJson($data);
        } else {
            Craft::$app->getSession()->setNotice($message);
            $response = $this->redirectToPostedUrl();
        }

        return $response;
    }

    /**
     * @param $request
     * @param $exception
     * @return Response
     * @throws BadRequestHttpException
     */
    private function renderErrorResponse(Request $request, Exception $exception): Response
    {
        if ($request->getIsAjax()) {
            $response = $this->asJson([
                'error' => $exception->getMessage(),
                'code' => $exception->getCode(),
            ]);

            $response->setStatusCode(500);

            if ($exception instanceof HttpException) {
                $response->setStatusCode($exception->statusCode);
            }

        } else {
            Craft::$app->getSession()->setError($exception->getMessage());
            $response = $this->redirectToPostedUrl();
        }

        return $response;
    }

    /**
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionCheckIfSubscribed()
    {
        $request = Craft::$app->getRequest();
        $requestHelper = new ListControllerRequestHelper();

        try {

            $listIds = $requestHelper->getListIds($request);
            $email = $this->getEmail($request);
            $member = $this->getMember($listIds, $email);
            return $this->asJson(['subscribed' => (bool)$member]);
        } catch (Exception $exception) {
            return $this->renderErrorResponse($request, $exception);
        }
    }

    /**
     * @param $listIds
     * @param $email
     * @return array
     * @throws MailChimpException
     */
    private function getMember($listIds, $email): array
    {
        $member = null;

        try {
            $member = $this->getClient()->send(new GetListMember($listIds, $email));
        } catch (MailChimpException $exception) {
            if ($exception->getCode() != 404) {
                throw $exception;
            }
        }
        return $member;
    }

    /**
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionGetLists()
    {
        $request = Craft::$app->getRequest();

        $apiKey = $this->getApiKey($request);
        $lists = $this->getLists($apiKey);

        return $this->parseLists($lists);
    }

    /**
     * @param $request
     * @return mixed
     * @throws BadRequestHttpException
     */
    private function getApiKey($request)
    {
        $apiKey = $request->getParam('apiKey');
        if (!$apiKey) {
            throw new BadRequestHttpException();
        }
        return $apiKey;
    }

    /**
     * @param $apiKey
     * @return mixed|ResponseInterface
     */
    private function getLists($apiKey)
    {
        $response = $this->getClient()->createClient($apiKey)
            ->send(new GetLists([
                'count' => 100,
                'fields' => 'lists.id,lists.name,lists.date_created,lists.list_rating,lists.visibility,lists.stats.member_count',
                'sort_field' => 'date_created',
                'sort_dir' => 'DESC',
            ]));
        return $response;
    }

    /**
     * @param $lists
     * @return Response
     */
    private function parseLists($lists): Response
    {
        $data = [];

        foreach ($lists['lists'] as $list) {
            $data[] = [
                'id' => $list['id'],
                'name' => $list['name'],
                'date_created' => $list['date_created'],
                'list_rating' => $list['list_rating'],
                'visibility' => $list['visibility'],
                'stats' => $list['stats'],
            ];
        }

        return $this->asJson($data);
    }
}
