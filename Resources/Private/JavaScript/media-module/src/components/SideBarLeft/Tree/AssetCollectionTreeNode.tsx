import * as React from 'react';
import { useCallback, useState } from 'react';

import { Tree } from '@neos-project/react-ui-components';

import dndTypes from '@media-ui/core/src/constants/dndTypes';
import { AssetCollection } from '@media-ui/core/src/interfaces';

import TreeNodeProps from './TreeNodeProps';

export interface AssetCollectionTreeNodeProps extends TreeNodeProps {
    assetCollection: AssetCollection;
    onClick: (assetCollection: AssetCollection) => void;
}

const AssetCollectionTreeNode: React.FC<AssetCollectionTreeNodeProps> = ({
    isActive,
    isFocused,
    assetCollection,
    label,
    title,
    children,
    level,
    onClick,
    collapsedByDefault = true,
}: AssetCollectionTreeNodeProps) => {
    const [collapsed, setCollapsed] = useState(collapsedByDefault);
    const handleToggle = useCallback(() => setCollapsed(!collapsed), [collapsed]);
    const handleClick = useCallback(() => onClick(assetCollection), [onClick, assetCollection]);
    return (
        <Tree.Node>
            <Tree.Node.Header
                isActive={isActive}
                isCollapsed={children.length === 0 || collapsed}
                isFocused={isFocused !== undefined ? isFocused : isActive}
                isLoading={false}
                hasError={false}
                label={label || assetCollection.title}
                title={title || assetCollection.title}
                icon={isActive ? 'folder-open' : 'folder'}
                nodeDndType={dndTypes.COLLECTION}
                level={level}
                onToggle={handleToggle}
                onClick={handleClick}
                hasChildren={children.length > 0}
            />
            {!collapsed && children}
        </Tree.Node>
    );
};

export default React.memo(AssetCollectionTreeNode);
