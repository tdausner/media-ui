import { ReactSelector } from 'testcafe-react-selectors';

import page from './page-model';
import { SERVER_NAME } from './helpers';

fixture('Inspector').page(SERVER_NAME);

test('Inspector appears and shows first asset', async (t) => {
    await t
        .click(page.firstThumbnail)
        .expect(page.assetInspector.find('input[name="label"]').withAttribute('value', 'Example asset 1').exists)
        .ok('The first asset should be selected');
});

test('Tagging works', async (t) => {
    await t
        .click(page.firstThumbnail)
        .scrollIntoView(page.tagSelection)
        .click(page.tagSelection)
        .click(ReactSelector('ListPreviewElement').withText('Example tag 1'))
        .click(page.inspectorActions.findReact('Button').withText('Apply'));
}).after(async (t) => {
    const { log } = await t.getBrowserConsoleMessages();
    await t
        .expect(log.includes('The asset has been tagged'))
        .ok('There should be a success response from the server in the logs');
});
