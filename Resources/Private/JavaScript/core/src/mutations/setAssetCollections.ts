import { gql } from '@apollo/client';

import { ASSET_FRAGMENT } from '../fragments/asset';

const SET_ASSET_COLLECTIONS = gql`
    mutation SetAssetCollections(
        $id: AssetId!
        $assetSourceId: AssetSourceId!
        $assetCollectionIds: [AssetCollectionId!]!
        $includeUsage: Boolean = false
    ) {
        includeUsage @client(always: true) @export(as: "includeUsage")
        setAssetCollections(id: $id, assetSourceId: $assetSourceId, assetCollectionIds: $assetCollectionIds) {
            ...AssetProps
        }
    }
    ${ASSET_FRAGMENT}
`;

export default SET_ASSET_COLLECTIONS;
