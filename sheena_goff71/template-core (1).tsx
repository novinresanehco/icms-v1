import React, { useState } from 'react';
import { Alert } from '@/components/ui/alert';

const ContentRenderer = ({ content }) => {
  const [state, setState] = useState({
    loading: true,
    error: null
  });

  const ContentDisplay = (
    <div className="w-full">
      <div className="prose max-w-none">
        {content?.type === 'rich-text' && (
          <div className="p-4">{content.body}</div>
        )}
        {content?.type === 'html' && (
          <div className="p-4" dangerouslySetInnerHTML={{ __html: content.sanitizedHtml }} />
        )}
      </div>
    </div>
  );

  const MediaDisplay = (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 p-4">
      {content?.media?.map((item, index) => (
        <div key={index} className="relative aspect-square bg-gray-100 rounded-lg overflow-hidden">
          <img
            src="/api/placeholder/400/400"
            alt={item.alt || ''}
            className="object-cover w-full h-full"
          />
        </div>
      ))}
    </div>
  );

  const LayoutWrapper = ({ children }) => (
    <div className="flex flex-col space-y-4">
      {state.error ? (
        <Alert variant="destructive">{state.error}</Alert>
      ) : children}
    </div>
  );

  return (
    <LayoutWrapper>
      {content?.type === 'media' ? MediaDisplay : ContentDisplay}
    </LayoutWrapper>
  );
};

export default ContentRenderer;
