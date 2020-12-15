<?php

namespace Dbwhddn10\FService\Cloud;

use Dbwhddn10\FService\Cloud\EvernoteNoteService;
use Dbwhddn10\FService\DB\OrderByFeatureService;
use Dbwhddn10\FService\DB\SkipFeatureService;
use Dbwhddn10\FService\Service;
use EDAM\NoteStore\NoteFilter;
use EDAM\Types\NoteSortOrder;

class EvernoteNoteListService extends Service
{
    public static function getArrBindNames()
    {
        return [];
    }

    public static function getArrCallbackLists()
    {
        return [
            'filter.order' => function ($filter, $orderBy) {

                $order = explode(' ', $orderBy)[0];

                if ( $order == 'created' )
                {
                    $filter->order = NoteSortOrder::CREATED;
                }
                else if ( $order == 'title' )
                {
                    $filter->order = NoteSortOrder::TITLE;
                }
                else if ( $order == 'updated' )
                {
                    $filter->order = NoteSortOrder::UPDATED;
                }
                else if ( $order == 'relevance' )
                {
                    $filter->order = NoteSortOrder::RELEVANCE;
                }
                else
                {
                    throw new \Exception;
                }
            },

            'filter.ascending' => function ($filter, $orderBy) {

                $ascending = explode(' ', $orderBy)[1];

                if ( $filter->ascending == 'asc' )
                {
                    $filter->ascending = $ascending;
                }
                else if ( $filter->ascending == 'desc' )
                {
                    $filter->ascending = $ascending;
                }
                else
                {
                    throw new \Exception;
                }
            },

            'filter.words' => function ($filter, $words) {

                $filter->words = $words;
            },
        ];
    }

    public static function getArrLoaders()
    {
        return [
            'available_order_by' => function () {

                return ['created asc', 'created desc', 'updated asc', 'updated desc', 'title asc', 'relevance desc'];
            },

            'client' => function ($token) {

                return [EvernoteNoteService::class, [
                    'token'
                        => $token,
                ]];
            },

            'filter' => function () {

                return new NoteFilter;
            },

            'order_by' => function () {

                return 'created desc';
            },

            'result' => function ($client, $filter, $skip, $limit) {

                return $client->findNotes($filter, $skip, $limit);
            },
        ];
    }

    public static function getArrPromiseLists()
    {
        return [];
    }

    public static function getArrRuleLists()
    {
        return [
            'order_by'
                => ['string', 'in:created asc,created desc,relevance desc,title asc,updated asc,updated desc'],

            'words'
                => ['string'],
        ];
    }

    public static function getArrTraits()
    {
        return [
            SkipFeatureService::class,
            OrderByFeatureService::class,
        ];
    }
}