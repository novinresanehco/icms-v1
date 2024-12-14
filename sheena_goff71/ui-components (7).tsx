import React, { useState } from 'react';
import { Image, ChevronLeft, ChevronRight } from 'lucide-react';

export default function ContentDisplay({ content, media }) {
  const [currentPage, setCurrentPage] = useState(1);
  const itemsPerPage = 12;

  // Calculate pagination
  const totalPages = Math.ceil(media.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const visibleMedia = media.slice(startIndex, startIndex + itemsPerPage);

  const nextPage = () => {
    if (currentPage < totalPages) setCurrentPage(current => current + 1);
  };

  const prevPage = () => {
    if (currentPage > 1) setCurrentPage(current => current - 1);
  };

  return (
    <div className="max-w-7xl mx-auto px-4">
      {/* Content Display */}
      <div className="prose max-w-none mb-8">
        {content}
      </div>

      {/* Media Gallery */}
      {media.length > 0 && (
        <div className="space-y-4">
          <h2 className="text-2xl font-bold">Media Gallery</h2>
          
          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            {visibleMedia.map((item, index) => (
              <div key={index} className="relative group">
                <img
                  src={item.thumbnail}
                  alt={item.alt}
                  className="w-full h-48 object-cover rounded-lg"
                />
                <div className="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 transition-all duration-300 rounded-lg" />
              </div>
            ))}
          </div>

          {/* Pagination Controls */}
          {totalPages > 1 && (
            <div className="flex items-center justify-center space-x-4 mt-6">
              <button
                onClick={prevPage}
                disabled={currentPage === 1}
                className="p-2 rounded-full hover:bg-gray-100 disabled:opacity-50"
              >
                <ChevronLeft className="w-6 h-6" />
              </button>

              <span className="text-sm">
                Page {currentPage} of {totalPages}
              </span>

              <button
                onClick={nextPage}
                disabled={currentPage === totalPages}
                className="p-2 rounded-full hover:bg-gray-100 disabled:opacity-50"
              >
                <ChevronRight className="w-6 h-6" />
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
