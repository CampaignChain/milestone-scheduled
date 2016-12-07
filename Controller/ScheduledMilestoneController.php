<?php
/*
 * Copyright 2016 CampaignChain, Inc. <info@campaignchain.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace CampaignChain\Milestone\ScheduledMilestoneBundle\Controller;

use CampaignChain\CoreBundle\EntityService\MilestoneService;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use CampaignChain\CoreBundle\Entity\Milestone;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ScheduledMilestoneController extends Controller
{
    const BUNDLE_NAME = 'campaignchain/milestone-scheduled';
    const MODULE_IDENTIFIER = 'campaignchain-scheduled';
    const FORMAT_DATEINTERVAL = 'Years: %Y, months: %m, days: %d, hours: %h, minutes: %i, seconds: %s';

    public function getLogger()
    {
        return $this->has('monolog.logger.external') ? $this->get('monolog.logger.external') : $this->get('monolog.logger');
    }

    public function indexAction(){
        $em = $this->getDoctrine()->getManager();
        $qb = $em->createQueryBuilder();
        $qb->select('m')
            ->from('CampaignChain\CoreBundle\Entity\Milestone', 'm')
            ->where('m.startDate IS NOT NULL')
            ->andWhere('m.endDate IS NULL')
            ->orderBy('m.startDate');
        $query = $qb->getQuery();
        $milestones = $query->getResult();

        return $this->render(
            'CampaignChainCoreBundle:Milestone:index.html.twig',
            array(
                'page_title' => 'Milestones',
                'milestones' => $milestones
            ));
    }

    public function newAction(Request $request, $campaign)
    {
        $campaignService = $this->get('campaignchain.core.campaign');
        $campaign = $campaignService->getCampaign($campaign);

        $milestoneType = $this->get('campaignchain.core.form.type.milestone');
        $milestoneType->setBundleName(self::BUNDLE_NAME);
        $milestoneType->setModuleIdentifier(self::MODULE_IDENTIFIER);
        $milestoneType->setCampaign($campaign);

        $milestone = new Milestone();
        $milestone->setCampaign($campaign);

        $form = $this->createForm($milestoneType, $milestone);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();

            // Make sure that data stays intact by using transactions.
            try {
                $em->getConnection()->beginTransaction();

                $em->persist($milestone);
                // We need the milestone ID for storing the hooks. Hence we must flush here.
                $em->flush();

                $hookService = $this->get('campaignchain.core.hook');
                $milestone = $hookService->processHooks(self::BUNDLE_NAME, self::MODULE_IDENTIFIER, $milestone, $form, true);

                $em->flush();

                $em->getConnection()->commit();
            } catch (\Exception $e) {
                $em->getConnection()->rollback();
                throw $e;
            }

            $this->get('session')->getFlashBag()->add(
                'success',
                'Your new milestone <a href="'.$this->generateUrl('campaignchain_core_milestone_edit', array('id' => $milestone->getId())).'">'.$milestone->getName().'</a> was created successfully.'
            );

            return $this->redirect($this->generateUrl('campaignchain_core_milestone'));
        }

        return $this->render(
            'CampaignChainCoreBundle:Milestone:new.html.twig',
            array(
                'page_title' => 'Create Scheduled Milestone',
                'form' => $form->createView(),
                'milestone' => $milestone,
            ));

        return $this->form($request, $milestone, 'Create New Milestone');
    }

    public function editAction(Request $request, $id){
        // TODO: If a milestone is over/done, it cannot be edited.
        $milestoneService = $this->get('campaignchain.core.milestone');
        $milestone = $milestoneService->getMilestone($id);

        $milestoneType = $this->get('campaignchain.core.form.type.milestone');
        $milestoneType->setBundleName(self::BUNDLE_NAME);
        $milestoneType->setModuleIdentifier(self::MODULE_IDENTIFIER);
        $milestoneType->setCampaign($milestone->getCampaign());

        $form = $this->createForm($milestoneType, $milestone);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();

            $hookService = $this->get('campaignchain.core.hook');
            $milestone = $hookService->processHooks(self::BUNDLE_NAME, self::MODULE_IDENTIFIER, $milestone, $form);
            $em->persist($milestone);

            $em->flush();

            $this->get('session')->getFlashBag()->add(
                'success',
                'Your milestone <a href="'.$this->generateUrl('campaignchain_core_milestone_edit', array('id' => $milestone->getId())).'">'.$milestone->getName().'</a> was edited successfully.'
            );

            return $this->redirect($this->generateUrl('campaignchain_core_milestone'));
        }

        return $this->render(
            'CampaignChainCoreBundle:Milestone:new.html.twig',
            array(
            'page_title' => 'Edit Scheduled Milestone',
            'form' => $form->createView(),
            'milestone' => $milestone,
        ));
    }

    public function editModalAction(Request $request, $id){
        // TODO: If a milestone is over/done, it cannot be edited.
        $milestoneService = $this->get('campaignchain.core.milestone');
        $milestone = $milestoneService->getMilestone($id);

        $milestoneType = $this->get('campaignchain.core.form.type.milestone');
        $milestoneType->setBundleName(self::BUNDLE_NAME);
        $milestoneType->setModuleIdentifier(self::MODULE_IDENTIFIER);
        $milestoneType->setView('default');
        $milestoneType->setCampaign($milestone->getCampaign());

        $form = $this->createForm($milestoneType, $milestone);

        return $this->render(
            'CampaignChainCoreBundle:Base:new_modal.html.twig',
            array(
                'page_title' => 'Edit Scheduled Milestone',
                'form' => $form->createView(),
            ));
    }

    public function editApiAction(Request $request, $id)
    {
        $responseData = array();

        $data = $request->get('campaignchain_core_milestone');

        // $responseData['payload'] = $data;

        $milestoneService = $this->get('campaignchain.core.milestone');
        $milestone = $milestoneService->getMilestone($id);
        $milestone->setName($data['name']);

        // Remember original dates.
        $responseData['start_date'] =
        $responseData['end_date'] =
            $milestone->getStartDate()->format(\DateTime::ISO8601);

        // Clear all flash bags.
        $this->get('session')->getFlashBag()->clear();

        $em = $this->getDoctrine()->getManager();

        // Make sure that data stays intact by using transactions.
        try {
            $em->getConnection()->beginTransaction();
            $em->persist($milestone);

            $hookService = $this->get('campaignchain.core.hook');
            $hookService->processHooks(self::BUNDLE_NAME, self::MODULE_IDENTIFIER, $milestone, $data);

            $em->flush();

            $responseData['start_date'] =
            $responseData['end_date'] =
                $milestone->getStartDate()->format(\DateTime::ISO8601);
            $responseData['success'] = true;

            $em->getConnection()->commit();
        } catch (\Exception $e) {
            $em->getConnection()->rollback();

            if($this->get('kernel')->getEnvironment() == 'dev'){
                $message = $e->getMessage().' '.$e->getFile().' '.$e->getLine().'<br/>'.$e->getTraceAsString();
            } else {
                $message = $e->getMessage();
            }

            $this->addFlash(
                'warning',
                $message
            );

            $this->getLogger()->error($e->getMessage(), array(
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTrace(),
            ));

            $responseData['message'] = $e->getMessage();
            $responseData['success'] = false;
        }
        $serializer = $this->get('campaignchain.core.serializer.default');

        return new Response($serializer->serialize($responseData, 'json'));
    }

    public function readAction(Request $request, $id)
    {
        /** @var MilestoneService $milestoneService */
        $milestoneService = $this->get('campaignchain.core.milestone');
        /** @var Milestone $milestone */
        $milestone = $milestoneService->getMilestone($id);

        return $this->render(
            'CampaignChainCoreBundle:Milestone:read.html.twig',
            array(
                'page_title' => 'View Milestone',
                'milestone' => $milestone,
            ));
    }
}