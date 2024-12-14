import React, { useState, useEffect } from 'react';

const ContentDisplay = ({ content, security }) => {
  const [validated, setValidated] = useState(false);

  useEffect(() => {
    if (security.validateAccess('content.display')) {
      setValidated(true);
    }
  }, [content, security]);

  return validated ? (
    <div className="p-4 bg-white shadow rounded">
      <div className="prose max-w-none"
           dangerouslySetInnerHTML={{__html: content}} />
    </div>
  ) : null;
};

const MediaGallery = ({ items, config }) => {
  const [media, setMedia] = useState([]);

  useEffect(() => {
    if (items?.length) {
      setMedia(items.map(item => ({
        ...item,
        url: `/secure-media/${item.id}`,
        thumb: `/secure-thumb/${item.id}`
      })));
    }
  }, [items]);

  return (
    <div className="grid grid-cols-3 gap-4">
      {media.map((item) => (
        <div key={item.id} className="relative group">
          <img 
            src={item.thumb}
            alt={item.title}
            className="w-full h-64 object-cover rounded transition-all"
            loading="lazy"
          />
          <div className="absolute inset-0 bg-black bg-opacity-50 opacity-0 group-hover:opacity-100 transition-opacity">
            <div className="p-4 text-white">
              <h3 className="text-lg font-bold">{item.title}</h3>
            </div>
          </div>
        </div>
      ))}
    </div>
  );
};

const TemplateRenderer = ({ template, data, security }) => {
  const [rendered, setRendered] = useState(null);

  useEffect(() => {
    if (security.validateTemplate(template)) {
      const content = processTemplate(template, data);
      setRendered(content);
    }
  }, [template, data, security]);

  return rendered ? (
    <div className="template-container">
      {rendered}
    </div>
  ) : null;
};

export default { ContentDisplay, MediaGallery, TemplateRenderer };
