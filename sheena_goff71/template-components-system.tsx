import React, { useState, useEffect } from 'react';

const TemplateRenderer = ({ template, components, data }) => {
  const [content, setContent] = useState(null);

  useEffect(() => {
    const processTemplate = async () => {
      try {
        const response = await fetch('/api/template/render', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({ template, data })
        });

        if (!response.ok) throw new Error('Template processing failed');
        const result = await response.json();
        setContent(result.content);
      } catch (error) {
        setContent(null);
      }
    };

    processTemplate();
  }, [template, data]);

  if (!content) {
    return (
      <div className="w-full h-32 flex items-center justify-center">
        <div className="w-8 h-8 border-t-2 border-gray-900 rounded-full animate-spin"/>
      </div>
    );
  }

  return (
    <div className="template-root w-full">
      {components.map((Component, index) => (
        <Component key={index} content={content} section={index} />
      ))}
    </div>
  );
};

const MediaDisplay = ({ items }) => {
  return (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
      {items.map((item, index) => (
        <div key={index} className="relative overflow-hidden rounded-lg bg-gray-100">
          <div className="aspect-w-16 aspect-h-9">
            <img 
              src={item.url} 
              alt={item.alt || ''}
              className="w-full h-full object-cover"
            />
          </div>
        </div>
      ))}
    </div>
  );
};

const ContentSection = ({ content, layout }) => {
  return (
    <div className={`content-section ${layout} w-full p-4`}>
      <div dangerouslySetInnerHTML={{ __html: content }} />
    </div>
  );
};

export default {
  TemplateRenderer,
  MediaDisplay,
  ContentSection
};
