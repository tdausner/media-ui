import { InMemoryCache } from '@apollo/client';
import { ApolloCache } from '@apollo/client/cache/core/cache';
import { NormalizedCacheObject } from '@apollo/client/cache/inmemory/types';

import { FeatureFlags } from '@media-ui/core/src/interfaces';

import { IdFromObjectResolver, PersistentStateManager } from './index';
import { ClipboardItems } from '@media-ui/feature-clipboard/src';

class CacheFactory {
    public static createCache(featureFlags: FeatureFlags): ApolloCache<NormalizedCacheObject> {
        return new InMemoryCache({
            dataIdFromObject: IdFromObjectResolver,
            typePolicies: {
                Query: {
                    fields: {
                        // These resolvers allow fetching single entities from the cache that were already retrieved from any previous query
                        asset(_, { args, toReference }) {
                            return args.id ? toReference({ __typename: 'Asset', id: args.id }) : null;
                        },
                        tag(_, { args, toReference }) {
                            return args.id ? toReference({ __typename: 'Tag', id: args.id }) : null;
                        },
                        assetCollection(_, { args, toReference }) {
                            return args.id ? toReference({ __typename: 'AssetCollection', id: args.id }) : null;
                        },
                        includeUsage() {
                            return featureFlags.queryAssetUsage;
                        },
                    },
                },
                Mutation: {
                    fields: {
                        includeUsage() {
                            return featureFlags.queryAssetUsage;
                        },
                    },
                },
                Asset: {
                    keyFields: ['id'],
                    fields: {
                        isInClipboard(_, { variables }) {
                            const clipboard = PersistentStateManager.getItem<ClipboardItems>('clipboard') || [];
                            return {}.hasOwnProperty.call(clipboard, variables.id);
                        },
                    },
                },
                Tag: {
                    keyFields: ['id'],
                },
                AssetCollection: {
                    keyFields: ['id'],
                },
                AssetSource: {
                    keyFields: ['id'],
                },
            },
        });
    }
}

export default CacheFactory;
