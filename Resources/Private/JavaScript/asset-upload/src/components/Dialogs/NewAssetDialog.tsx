import * as React from 'react';
import { useCallback } from 'react';

import { Button } from '@neos-project/react-ui-components';

import { createUseMediaUiStyles, MediaUiTheme, useIntl, useMediaUi, useNotify } from '@media-ui/core/src';
import { Dialog } from '@media-ui/core/src/components';

import UploadSection from '../UploadSection';
import PreviewSection from '../PreviewSection';
import { useUploadDialogState, useUploadFiles } from '../../hooks';
import { FilesUploadState, UploadedFile } from '../../interfaces';

const useStyles = createUseMediaUiStyles((theme: MediaUiTheme) => ({
    uploadArea: {
        padding: theme.spacing.full,
    },
}));

const NewAssetDialog: React.FC = () => {
    const { translate } = useIntl();
    const Notify = useNotify();
    const { uploadFiles, uploadState, loading } = useUploadFiles();
    const { state: dialogState, closeDialog, setFiles } = useUploadDialogState();
    const { refetchAssets } = useMediaUi();
    const uploadPossible = !loading && dialogState.files.selected.length > 0;

    const classes = useStyles();

    const handleUpload = useCallback(() => {
        uploadFiles(dialogState.files.selected)
            .then(({ data: { uploadFiles } }) => {
                // FIXME: Mapping the uploadState to the files name is not the best solution as the same filename might be used multiple times
                // Move uploaded or failed files into separate lists
                setFiles((prev) => {
                    return {
                        selected: [],
                        finished: [
                            ...prev.finished,
                            ...prev.selected.filter((file) =>
                                uploadFiles.find((result) => result.success && result.filename === file.name)
                            ),
                        ],
                        rejected: [
                            ...prev.rejected,
                            ...prev.selected.filter((file) =>
                                uploadFiles.find((result) => !result.success && result.filename === file.name)
                            ),
                        ],
                    } as FilesUploadState;
                });
                if (uploadFiles.some((result) => !result.success)) {
                    Notify.warning(
                        translate('uploadDialog.uploadFinishedWithErrors', 'Some files could not be uploaded')
                    );
                } else {
                    Notify.ok(translate('uploadDialog.uploadFinished', 'Upload finished'));
                }

                // Refresh list of files if any file was uploaded
                if (uploadFiles.some((result) => result.success)) {
                    void refetchAssets();
                }
            })
            .catch((error) => {
                Notify.error(translate('fileUpload.error', 'Upload failed'), error);
            });
    }, [uploadFiles, dialogState.files.selected, setFiles, Notify, translate, refetchAssets]);

    const handleSetFiles = useCallback(
        (files: UploadedFile[]) => {
            setFiles((prev) => {
                return { ...prev, selected: files };
            });
        },
        [setFiles]
    );

    return (
        <Dialog
            isOpen={dialogState.visible}
            title={translate('uploadDialog.title', 'Upload assets')}
            onRequestClose={closeDialog}
            actions={[
                <Button key="cancel" style="neutral" hoverStyle="darken" onClick={closeDialog}>
                    {uploadState
                        ? translate('uploadDialog.close', 'Close')
                        : translate('uploadDialog.cancel', 'Cancel')}
                </Button>,
                <Button
                    key="upload"
                    style="success"
                    hoverStyle="success"
                    disabled={!uploadPossible}
                    onClick={handleUpload}
                >
                    {translate('uploadDialog.upload', 'Upload')}
                </Button>,
            ]}
            style="wide"
        >
            <section className={classes.uploadArea}>
                <UploadSection files={dialogState.files.selected} loading={loading} onSetFiles={handleSetFiles} />
                <PreviewSection files={dialogState.files} loading={loading} uploadState={uploadState} />
            </section>
        </Dialog>
    );
};

export default React.memo(NewAssetDialog);
